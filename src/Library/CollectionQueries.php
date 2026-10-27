<?php

declare(strict_types=1);

namespace App\Library;

use App\Api\ApiProblem;
use App\Api\Library\CollectionView;
use App\Api\Pagination;
use App\Entity\Comuna;
use App\Entity\User;
use App\Repository\ComunaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final readonly class CollectionQueries
{
    public function __construct(private EntityManagerInterface $em, private LibraryAccess $policy, private ComunaRepository $comunas)
    {
    }

    /** @return array{list<CollectionView>, ?string} */
    public function page(User $viewer, Request $request, Pagination $page, ?string $id = null): array
    {
        $scope = $request->query->getString('scope', 'public');
        if (!in_array($scope, ['personal', 'comuna', 'public'], true)) {
            throw ApiProblem::badRequest('/errors/library-filter', 'Biblioteca inválida.');
        }
        $params = ['viewer' => $viewer->getId()->toRfc4122()];
        $read = '('.LibraryAccess::readable().')'.('public' === $scope && null === $id ? ' AND d.is_public' : '');
        $visible = 'EXISTS (SELECT 1 FROM '.LibraryAccess::FROM.' WHERE d.collection_id = cl.id AND '.$read.')';
        $member = 'EXISTS (SELECT 1 FROM comuna_membership m WHERE m.comuna_id = cl.comuna_id AND m.user_id = :viewer)';
        $where = '('.$visible;
        if ('public' !== $scope || null !== $id) {
            $where .= ' OR cl.user_id = :viewer OR '.$member;
        }
        $where .= ')';
        if (null !== $id) {
            if (!Uuid::isValid($id)) {
                throw ApiProblem::notFound();
            }
            $where .= ' AND cl.id = :id';
            $params['id'] = $id;
        } elseif ('personal' === $scope) {
            $owner = $request->query->getString('owner', $params['viewer']);
            if (!Uuid::isValid($owner)) {
                throw ApiProblem::badRequest('/errors/library-owner', 'Biblioteca inválida.');
            }
            $where .= ' AND cl.user_id = :owner';
            $params['owner'] = $owner;
        } elseif ('comuna' === $scope) {
            $comuna = $this->comunas->findOneBySlug($request->query->getString('comuna')) ?? throw ApiProblem::notFound();
            if (!$comuna->isPublic() && null === $this->policy->memberships->findMembership($comuna, $viewer)) {
                throw ApiProblem::notFound();
            }
            $where .= ' AND cl.comuna_id = :comuna';
            $params['comuna'] = $comuna->getId()->toRfc4122();
        }
        if (null === $id && null !== $page->cursor) {
            $where .= ' AND cl.id < :cursor';
            $params['cursor'] = $page->cursor->toRfc4122();
        }
        $rows = $this->em->getConnection()->executeQuery('SELECT cl.*, u.display_name, c.name AS comuna_name, c.slug, c.status AS comuna_status, (SELECT COUNT(*) FROM '.LibraryAccess::FROM.' WHERE d.collection_id = cl.id AND '.$read.') AS document_count FROM library_collection cl LEFT JOIN app_user u ON u.id = cl.user_id LEFT JOIN comuna c ON c.id = cl.comuna_id WHERE '.$where.' ORDER BY cl.id DESC LIMIT '.($page->limit + 1), $params)->fetchAllAssociative();
        $more = count($rows) > $page->limit;
        $rows = array_slice($rows, 0, $page->limit);
        $comunaIds = array_values(array_unique(array_filter(array_column($rows, 'comuna_id'))));
        /** @var list<Comuna> $comunas */
        $comunas = [] === $comunaIds ? [] : $this->em->getRepository(Comuna::class)->findBy(['id' => $comunaIds]);
        $memberships = $this->policy->memberships->findForComunas($viewer, $comunas);
        $items = [];
        foreach ($rows as $row) {
            $own = $row['user_id'] === $viewer->getId()->toRfc4122();
            $membership = $memberships[$row['comuna_id'] ?? ''] ?? null;
            $active = 'archived' !== $row['comuna_status'];
            $manage = $own || (null !== $membership && ($membership->getRole()->canModerate() || $row['created_by_id'] === $viewer->getId()->toRfc4122()));
            $items[] = new CollectionView((string) $row['id'], (string) $row['name'], (string) $row['description'],
                null === $row['comuna_id'] ? ['kind' => 'personal', 'id' => $row['user_id'], 'name' => 'Biblioteca de '.$row['display_name']]
                    : ['kind' => 'comuna', 'id' => $row['comuna_id'], 'slug' => $row['slug'], 'name' => 'c/ '.$row['comuna_name']],
                (int) $row['document_count'], $manage, $viewer->isEmailVerified() && $active && ($own || null !== $membership));
        }
        $last = end($items);

        return [$items, $more && false !== $last ? $last->id : null];
    }
}
