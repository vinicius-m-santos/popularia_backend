<?php

declare(strict_types=1);

namespace App\Content;

use App\Api\ApiProblem;
use App\Entity\Comuna;
use Doctrine\DBAL\Connection;

/** Filtro mínimo do beta: impede republicar texto idêntico já removido no mesmo espaço. Sem classificador ideológico. */
final readonly class RemovedContentFilter
{
    public function __construct(private Connection $db)
    {
    }

    public function assertText(string $text, ?Comuna $comuna): void
    {
        $text = TextNormalizer::normalize($text);
        if ('' === $text) {
            return;
        }
        $scope = $comuna?->getId()->toRfc4122();
        $blocked = $this->db->fetchOne("SELECT EXISTS (
            SELECT 1 FROM post p WHERE p.status = 'removed' AND p.body = :body AND p.comuna_id IS NOT DISTINCT FROM CAST(:scope AS uuid)
            UNION ALL SELECT 1 FROM comment c JOIN post p ON p.id = c.post_id WHERE c.status = 'removed' AND c.body = :body AND p.comuna_id IS NOT DISTINCT FROM CAST(:scope AS uuid)
            UNION ALL SELECT 1 FROM square_message m JOIN square s ON s.id = m.square_id WHERE m.status = 'removed' AND m.body = :body AND s.comuna_id IS NOT DISTINCT FROM CAST(:scope AS uuid)
        )", ['body' => $text, 'scope' => $scope]);
        if ($blocked) {
            throw new ApiProblem(422, '/errors/content-filtered', 'Este conteúdo já foi removido neste espaço. Revise o texto ou fale com o suporte.');
        }
    }

    public function assertPdf(string $checksum, ?Comuna $comuna): void
    {
        if ($this->db->fetchOne("SELECT EXISTS (SELECT 1 FROM library_file f JOIN library_document d ON d.file_id = f.id WHERE f.checksum = :checksum AND d.status = 'removed' AND d.comuna_id IS NOT DISTINCT FROM CAST(:scope AS uuid))", ['checksum' => $checksum, 'scope' => $comuna?->getId()->toRfc4122()])) {
            throw new ApiProblem(422, '/errors/content-filtered', 'Este PDF já foi removido neste espaço. Fale com o suporte para pedir revisão.');
        }
    }
}
