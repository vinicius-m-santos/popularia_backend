<?php

declare(strict_types=1);

namespace App\Account;

use App\Api\ApiProblem;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/** Política completa e ordem de limpeza: docs/account-deletion.md. Sem chamadas S3 na transação. */
final readonly class AccountDeletionService
{
    public function __construct(private EntityManagerInterface $em, private ClockInterface $clock)
    {
    }

    /** @param (callable(): void)|null $revokeApple */
    public function delete(User $user, ?callable $revokeApple = null): void
    {
        $db = $this->em->getConnection();
        $db->transactional(function () use ($user, $revokeApple, $db): void {
            $id = $user->getId()->toRfc4122();
            $now = $this->clock->now()->format('Y-m-d H:i:sP');
            $db->executeQuery('SELECT id FROM comuna WHERE owner_id = ? ORDER BY id FOR UPDATE', [$id])->fetchFirstColumn();
            $db->executeQuery('SELECT id FROM app_user WHERE id = ? FOR UPDATE', [$id])->fetchOne();
            $owned = $db->fetchFirstColumn("SELECT slug FROM comuna WHERE owner_id = ? AND status = 'active' ORDER BY slug", [$id]);
            if ([] !== $owned) {
                throw ApiProblem::conflict('/errors/account-owns-comunas', 'Transfira a propriedade ou arquive suas Comunas ativas antes de excluir a conta.', implode(', ', $owned));
            }
            // Falha do provider impede confirmar exclusão incompleta; nada local foi apagado ainda.
            if (null !== $revokeApple) {
                $revokeApple();
            }
            $user->eraseAccount($this->clock->now());
            $this->em->flush();

            foreach ([
                'DELETE FROM auth_session WHERE user_id = ?', // refresh_token cascades explicitamente verificados nos testes
                'DELETE FROM account_token WHERE user_id = ?',
                'DELETE FROM notification_preference WHERE user_id = ?',
                'DELETE FROM user_auth_identity WHERE user_id = ?',
                'DELETE FROM user_follow WHERE follower_id = ? OR followed_id = ?',
                'DELETE FROM user_block WHERE blocker_id = ? OR blocked_id = ?',
                'DELETE FROM notification WHERE recipient_id = ? OR actor_id = ?',
                'DELETE FROM content_mention WHERE mentioned_user_id = ?',
                'DELETE FROM content_mention WHERE post_id IN (SELECT id FROM post WHERE author_id = ?)',
                'DELETE FROM content_mention WHERE comment_id IN (SELECT id FROM comment WHERE author_id = ?)',
                'DELETE FROM content_report WHERE reporter_id = ?',
                'DELETE FROM library_save WHERE user_id = ?',
                'DELETE FROM saved_post WHERE user_id = ?',
                'DELETE FROM gamification_entry WHERE user_id = ?',
                'DELETE FROM gamification_mission WHERE user_id = ?',
                'DELETE FROM gamification_achievement WHERE user_id = ?',
                'DELETE FROM gamification_profile WHERE user_id = ?',
                'DELETE FROM square_visit WHERE user_id = ?',
                'DELETE FROM comuna_join_request WHERE user_id = ?',
                'DELETE FROM comuna_ownership_transfer WHERE from_user_id = ? OR to_user_id = ?',
            ] as $sql) {
                $db->executeStatement($sql, array_fill(0, substr_count($sql, '?'), $id));
            }
            // Comentários próprios em publicações de outras pessoas deixam de entrar na contagem.
            $db->executeStatement("UPDATE post p SET comment_count = GREATEST(0, p.comment_count - c.n) FROM (SELECT post_id, COUNT(*)::int n FROM comment WHERE author_id = ? AND status = 'published' GROUP BY post_id) c WHERE p.id = c.post_id", [$id]);
            $db->executeStatement("UPDATE comment SET body = '', status = 'deleted' WHERE author_id = ?", [$id]);
            $db->executeStatement("UPDATE post_media SET status = 'purge_pending' WHERE id IN (SELECT media_id FROM post WHERE author_id = ? AND media_id IS NOT NULL)", [$id]);
            // Publicações apagadas levam a enquete junto. Votos desta conta em enquetes de outras pessoas permanecem no agregado, sem lista de quem votou.
            $db->executeStatement('DELETE FROM poll WHERE post_id IN (SELECT id FROM post WHERE author_id = ?)', [$id]);
            $db->executeStatement('DELETE FROM saved_post WHERE post_id IN (SELECT id FROM post WHERE author_id = ?)', [$id]);
            $db->executeStatement("UPDATE post SET body = '', status = 'deleted', topic = NULL, library_document_id = NULL, media_id = NULL WHERE author_id = ?", [$id]);
            $db->executeStatement("UPDATE post_media SET status = 'purge_pending' WHERE uploaded_by_id = ? AND status <> 'purged'", [$id]);

            // Alterações de autoria e reações precisam de revisões novas para os clientes em polling.
            $squares = $db->fetchFirstColumn('SELECT DISTINCT square_id FROM square_message WHERE author_id = ? OR id IN (SELECT message_id FROM square_reaction WHERE user_id = ?) ORDER BY square_id', [$id, $id]);
            foreach ($squares as $square) {
                $version = (int) $db->fetchOne('SELECT version FROM square WHERE id = ? FOR UPDATE', [$square]);
                $messages = $db->fetchFirstColumn('SELECT id FROM square_message WHERE square_id = ? AND (author_id = ? OR id IN (SELECT message_id FROM square_reaction WHERE user_id = ?)) ORDER BY id', [$square, $id, $id]);
                foreach ($messages as $message) {
                    $db->executeStatement('UPDATE square_message SET revision = ? WHERE id = ?', [++$version, $message]);
                }
                $db->executeStatement('UPDATE square SET version = ?, updated_at = ? WHERE id = ?', [$version, $now, $square]);
            }
            $db->executeStatement("UPDATE square_message SET body = NULL, sticker_id = NULL, status = 'deleted', deleted_at = ?, client_id = id::text WHERE author_id = ?", [$now, $id]);
            $db->executeStatement('DELETE FROM square_reaction WHERE user_id = ? OR message_id IN (SELECT id FROM square_message WHERE author_id = ?)', [$id, $id]);
            $db->executeStatement('UPDATE square SET created_by_id = NULL WHERE created_by_id = ?', [$id]);

            // Origem pessoal é apagada mesmo quando havia distribuição em outra Comuna.
            // Origem coletiva permanece da Comuna; arquivo não é duplicado nem transferido.
            $db->executeStatement('DELETE FROM library_share WHERE document_id IN (SELECT id FROM library_document WHERE user_id = ?)', [$id]);
            $db->executeStatement('DELETE FROM library_save WHERE document_id IN (SELECT id FROM library_document WHERE user_id = ?)', [$id]);
            $db->executeStatement("UPDATE library_document SET title = 'Documento excluído', description = '', status = 'deleted', is_public = FALSE, topic = NULL, removed_at = COALESCE(removed_at, ?), updated_at = ?, removal_reason = NULL WHERE user_id = ?", [$now, $now, $id]);
            $db->executeStatement("UPDATE library_collection SET name = 'Coleção excluída', description = '' WHERE user_id = ?", [$id]);
            $db->executeStatement("UPDATE library_file SET original_filename = 'documento.pdf' WHERE uploaded_by_id = ?", [$id]);
            $db->executeStatement("UPDATE library_file f SET status = 'purge_pending' WHERE uploaded_by_id = ? AND f.status <> 'purged' AND NOT EXISTS (SELECT 1 FROM library_document d WHERE d.file_id = f.id AND d.comuna_id IS NOT NULL)", [$id]);

            $db->executeStatement('UPDATE comuna c SET member_count = GREATEST(0, c.member_count - 1) WHERE EXISTS (SELECT 1 FROM comuna_membership m WHERE m.comuna_id = c.id AND m.user_id = ?)', [$id]);
            $db->executeStatement('DELETE FROM comuna_membership WHERE user_id = ?', [$id]); // shares vinculadas à participação também caem
            // Comunas arquivadas podem ficar sem proprietário; continuam arquivadas e com a sua Biblioteca.
            $db->executeStatement("UPDATE comuna SET owner_id = NULL WHERE owner_id = ? AND status = 'archived'", [$id]);
        });
    }
}
