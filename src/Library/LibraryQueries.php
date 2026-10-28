<?php

declare(strict_types=1);

namespace App\Library;

use App\Api\ApiProblem;
use App\Api\Pagination;
use App\Entity\LibraryDocument;
use App\Entity\User;
use App\Enum\Topic;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class LibraryQueries
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return array{list<LibraryDocument>, ?string} */
    public function documents(User $viewer, Request $request, Pagination $page): array
    {
        $scope = $request->query->getString('scope', 'public');
        $origin = $request->query->getString('origin', 'all');
        $topic = $request->query->getString('topic');
        $term = trim($request->query->getString('q'));
        $sort = $request->query->getString('sort', '' !== $term ? 'relevance' : 'recent');
        if (!in_array($scope, ['public', 'personal', 'comuna'], true) || !in_array($origin, ['all', 'people', 'comunas'], true)
            || !in_array($sort, ['relevance', 'recent'], true) || ('' !== $topic && null === Topic::tryFrom($topic)) || mb_strlen($term) > 100) {
            throw ApiProblem::badRequest('/errors/library-filter', 'Filtro de biblioteca inválido.');
        }
        $where = '('.('comuna' === $scope ? LibraryAccess::visibleCard() : LibraryAccess::readable()).')';
        $params = ['viewer' => $viewer->getId()->toRfc4122()];
        if ('public' === $scope) {
            $where .= ' AND d.is_public';
        } elseif ('personal' === $scope) {
            $where .= ' AND d.user_id = :owner';
            $params['owner'] = $request->query->getString('owner', $params['viewer']);
            if (!\Symfony\Component\Uid\Uuid::isValid($params['owner'])) {
                throw ApiProblem::badRequest('/errors/library-owner', 'Biblioteca inválida.');
            }
        } else {
            $where .= ' AND (c.slug = :comuna OR EXISTS (SELECT 1 FROM library_share cs JOIN comuna cc ON cc.id = cs.comuna_id WHERE cs.status = \'published\' AND cs.document_id = d.id AND cc.slug = :comuna))';
            $params['comuna'] = $request->query->getString('comuna');
            // A distribuição pública de um documento privado não abre a biblioteca privada inteira.
            $where .= " AND EXISTS (SELECT 1 FROM comuna target WHERE target.slug = :comuna AND (target.visibility = 'public' OR EXISTS (SELECT 1 FROM comuna_membership tm WHERE tm.comuna_id = target.id AND tm.user_id = :viewer)))";
        }
        if ('people' === $origin) {
            $where .= ' AND d.user_id IS NOT NULL';
        } elseif ('comunas' === $origin) {
            $where .= ' AND d.comuna_id IS NOT NULL';
        }
        if ('' !== $topic) {
            $where .= ' AND d.topic = :topic AND ('.LibraryAccess::readable().')';
            $params['topic'] = $topic;
        }
        $collection = $request->query->getString('collection');
        if ('' !== $collection) {
            if (!\Symfony\Component\Uid\Uuid::isValid($collection)) {
                throw ApiProblem::badRequest('/errors/library-collection', 'Coleção inválida.');
            }
            $where .= ' AND d.collection_id = :collection AND ('.LibraryAccess::readable().')';
            $params['collection'] = $collection;
        }
        if ('' !== $term) {
            $topicLabels = 'CASE d.topic ';
            foreach (Topic::cases() as $t) {
                $topicLabels .= "WHEN '".$t->value."' THEN ".$this->em->getConnection()->quote($t->describe()['label']).' ';
            }
            $topicLabels .= "ELSE '' END";
            $where .= ' AND (('.LibraryAccess::readable().") AND (popularia_search_text(d.title || ' ' || d.description) LIKE popularia_search_text(:q) ESCAPE '!'
                OR popularia_search_text(COALESCE(d.author, '')) LIKE popularia_search_text(:q) ESCAPE '!'
                OR popularia_search_text(col.name) LIKE popularia_search_text(:q) ESCAPE '!'
                OR popularia_search_text(a.display_name || ' ' || a.handle) LIKE popularia_search_text(:q) ESCAPE '!'
                OR popularia_search_text(COALESCE(c.name, '')) LIKE popularia_search_text(:q) ESCAPE '!'
                OR popularia_search_text($topicLabels) LIKE popularia_search_text(:q) ESCAPE '!') OR popularia_search_text(d.title) LIKE popularia_search_text(:q) ESCAPE '!')";
            $params['q'] = '%'.strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        }
        $rank = '0';
        $ranked = '' !== $term && 'relevance' === $sort;
        if ($ranked) {
            $rank = "CASE WHEN popularia_search_text(d.title) = popularia_search_text(:exact) THEN 3 WHEN popularia_search_text(d.title) LIKE popularia_search_text(:prefix) ESCAPE '!' THEN 2 WHEN popularia_search_text(d.title) LIKE popularia_search_text(:q) ESCAPE '!' THEN 1 ELSE 0 END";
            $params['exact'] = $term;
            $params['prefix'] = strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        }
        if (null !== $page->cursor) {
            if ($ranked) {
                $cursorRank = $request->attributes->get('library_rank');
                if (!is_int($cursorRank)) {
                    throw ApiProblem::badRequest('/errors/invalid-cursor', 'Use o próximo cursor retornado pela biblioteca.');
                }
                $where .= " AND (($rank) < :rank OR (($rank) = :rank AND d.id < :cursor))";
                $params['rank'] = $cursorRank;
            } else {
                $where .= ' AND d.id < :cursor';
            }
            $params['cursor'] = $page->cursor->toRfc4122();
        }
        /** @var list<array{id: string, relevance: int|string}> $rows */
        $rows = $this->em->getConnection()->executeQuery('SELECT d.id, ('.$rank.') AS relevance FROM '.LibraryAccess::FROM.' WHERE '.$where.' ORDER BY relevance DESC, d.id DESC LIMIT '.($page->limit + 1), $params)->fetchAllAssociative();
        $more = count($rows) > $page->limit;
        $rows = array_slice($rows, 0, $page->limit);
        $ids = array_column($rows, 'id');
        $last = end($rows);
        $next = $more && false !== $last ? ($ranked ? 'r'.$last['relevance'].'_' : '').$last['id'] : null;

        return [$this->hydrate($ids), $next];
    }

    /** @param list<string> $ids
     * @return list<LibraryDocument>
     */
    public function hydrate(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<LibraryDocument> $documents */
        $documents = $this->em->createQueryBuilder()->select('d', 'f', 'a', 'col', 'u', 'c')->from(LibraryDocument::class, 'd')
            ->join('d.file', 'f')->join('f.uploadedBy', 'a')->join('d.collection', 'col')->leftJoin('d.user', 'u')->leftJoin('d.comuna', 'c')
            ->where('d.id IN (:ids)')->setParameter('ids', $ids)->orderBy('d.id', 'DESC')->getQuery()->getResult();
        $positions = array_flip($ids);
        usort($documents, static fn (LibraryDocument $a, LibraryDocument $b) => $positions[$a->id->toRfc4122()] <=> $positions[$b->id->toRfc4122()]);

        return $documents;
    }

    /**
     * Uma decisão por documento, em lote, antes de serializar; também revalida após hidratar.
     *
     * @param list<LibraryDocument> $documents
     *
     * @return array<string, bool>
     */
    public function readableIds(array $documents, User $viewer): array
    {
        if ([] === $documents) {
            return [];
        }
        $rows = $this->em->getConnection()->executeQuery('SELECT d.id FROM '.LibraryAccess::FROM.' WHERE d.id IN (:ids) AND ('.LibraryAccess::readable().')', [
            'ids' => array_map(static fn (LibraryDocument $d) => $d->id->toRfc4122(), $documents), 'viewer' => $viewer->getId()->toRfc4122(),
        ], ['ids' => ArrayParameterType::STRING])->fetchFirstColumn();

        return array_fill_keys($rows, true);
    }
}
