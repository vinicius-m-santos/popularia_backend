<?php

declare(strict_types=1);

namespace App\Mention;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Api\Mention\MentionInput;
use App\Entity\Comment;
use App\Entity\ContentMention;
use App\Entity\Post;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Liga menções escolhidas no editor ao usuário estável.
 * Texto solto com @ não vira menção: só entra o que o cliente marcou e o texto confirma.
 */
final readonly class MentionBinder
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param list<MentionInput> $inputs
     *
     * @return list<ContentMention>
     */
    public function attach(string $body, array $inputs, Post|Comment $content): array
    {
        if ([] === $inputs) {
            return [];
        }
        if (\count($inputs) > ContentMention::MAX_PER_CONTENT) {
            $this->fail('Mencione no máximo '.ContentMention::MAX_PER_CONTENT.' pessoas.');
        }

        $wanted = [];
        foreach ($inputs as $input) {
            if (!Uuid::isValid($input->userId)) {
                $this->fail('Menção inválida.');
            }
            $wanted[$input->userId] = Uuid::fromString($input->userId);
        }
        /** @var list<User> $users */
        $users = $this->em->getRepository(User::class)->findBy(['id' => array_values($wanted)]);
        $byId = [];
        foreach ($users as $user) {
            $byId[$user->getId()->toRfc4122()] = $user;
        }

        $length = mb_strlen($body, 'UTF-8');
        $ranges = [];
        $mentions = [];
        foreach ($inputs as $input) {
            $user = $byId[$input->userId] ?? null;
            if (null === $user || !$user->isActive()) {
                $this->fail('Não é possível mencionar esta pessoa.');
            }
            if ($input->start < 0 || $input->end <= $input->start || $input->end > $length) {
                $this->fail('A menção está fora do texto.');
            }
            $slice = mb_substr($body, $input->start, $input->end - $input->start, 'UTF-8');
            if ($slice !== '@'.$user->getHandle()) {
                $this->fail('A menção não corresponde ao texto.');
            }
            $this->assertBoundary($body, $input->start, $input->end, $length);
            $ranges[] = [$input->start, $input->end];
            $mentions[] = $content instanceof Post
                ? new ContentMention($content, null, $user, $input->start, $input->end)
                : new ContentMention(null, $content, $user, $input->start, $input->end);
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $previousEnd = -1;
        foreach ($ranges as [$start, $end]) {
            if ($start < $previousEnd) {
                $this->fail('As menções não podem se sobrepor.');
            }
            $previousEnd = $end;
        }

        return $mentions;
    }

    private function assertBoundary(string $body, int $start, int $end, int $length): void
    {
        if ($start > 0 && 1 === preg_match('/[A-Za-z0-9._@]/u', mb_substr($body, $start - 1, 1, 'UTF-8'))) {
            $this->fail('A menção não corresponde ao texto.');
        }
        if ($end >= $length) {
            return;
        }
        $next = mb_substr($body, $end, 1, 'UTF-8');
        if (1 === preg_match('/[A-Za-z0-9_]/u', $next)) {
            $this->fail('A menção não corresponde ao texto.');
        }
        if ('.' === $next && $end + 1 < $length && 1 === preg_match('/[A-Za-z0-9_]/u', mb_substr($body, $end + 1, 1, 'UTF-8'))) {
            $this->fail('A menção não corresponde ao texto.');
        }
    }

    private function fail(string $message): never
    {
        throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], null, 'mentions', null)]));
    }
}
