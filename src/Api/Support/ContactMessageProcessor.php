<?php
declare(strict_types=1);
namespace App\Api\Support;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Mail\AccountMailer;
use App\Security\CurrentUser;
/** @implements ProcessorInterface<ContactMessage, null> */
final readonly class ContactMessageProcessor implements ProcessorInterface
{
    public function __construct(private CurrentUser $currentUser, private AccountMailer $mailer) {}
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->mailer->sendSupportMessage($this->currentUser->get(), trim($data->message));
        return null;
    }
}
