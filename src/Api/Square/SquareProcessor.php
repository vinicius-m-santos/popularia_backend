<?php

declare(strict_types=1);

namespace App\Api\Square;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Post\ContentRemovalInput;
use App\Security\CurrentUser;
use App\Square\SquareAccess;
use App\Square\SquareQueries;
use App\Square\SquareService;
use App\Square\SquareViews;

/** @implements ProcessorInterface<MessageInput|ReadInput|StickerInput|ReportDecisionInput|ContentRemovalInput|null, SquareView|MessageView|PresenceView|StickerPage|null> */
final readonly class SquareProcessor implements ProcessorInterface
{
    public function __construct(private CurrentUser $currentUser, private SquareService $service, private SquareAccess $access, private SquareViews $views, private SquareQueries $queries)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SquareView|MessageView|PresenceView|StickerPage|null
    {
        $actor = $this->currentUser->get();
        $id = (string) ($uriVariables['id'] ?? '');
        $messageId = (string) ($uriVariables['messageId'] ?? '');
        switch ($operation->getName()) {
            case 'square_send':
                assert($data instanceof MessageInput);

                return $this->views->messages([$this->service->send($id, $actor, $data)], $actor)[0];
            case 'square_delete':
                $this->service->delete($id, $messageId, $actor);
                break;
            case 'square_remove':
                assert($data instanceof ContentRemovalInput);
                $this->service->delete($id, $messageId, $actor, $data->reason);
                break;
            case 'square_react':
            case 'square_unreact':
                return $this->views->messages([$this->service->reaction($id, $messageId, (string) $uriVariables['emoji'], $actor, 'square_react' === $operation->getName())], $actor)[0];
            case 'square_heartbeat':
                $square = $this->access->find($id);
                $this->access->read($square, $actor);
                $this->access->active($square);
                $this->queries->heartbeat($square, $actor);

                return new PresenceView($this->queries->stats([$square], $actor)[$id]['presence'], $this->queries->presence($square, $actor));
            case 'square_read':
                assert($data instanceof ReadInput);
                $square = $this->access->find($id);
                $message = $this->access->message($data->messageId, $square, $actor, false);
                $this->queries->read($square, $actor, $message->id);
                break;
            case 'square_add_sticker':
                assert($data instanceof StickerInput);
                $this->service->addSticker($id, $actor, $data);

                return new StickerPage($this->service->packs($this->access->find($id), $actor));
            case 'square_delete_sticker':
                $this->service->deleteSticker($id, (string) $uriVariables['stickerId'], $actor);
                break;
            case 'square_report':
                assert($data instanceof ContentRemovalInput);
                $this->service->report($id, $messageId, $actor, $data->reason);
                break;
            case 'square_resolve_report':
                assert($data instanceof ReportDecisionInput);
                $this->service->resolveReport((string) $uriVariables['slug'], (string) $uriVariables['reportId'], $actor, $data->status);
                break;
        }

        return null;
    }
}
