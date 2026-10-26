<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Service\ResetInterface;

/**
 * E-mails da conta. São enviados só depois que a resposta HTTP sai (kernel.terminate):
 * assim o tempo de resposta de cadastro e recuperação é o mesmo exista ou não a conta,
 * e uma falha do servidor de e-mail não derruba a requisição.
 */
final class AccountMailer implements ResetInterface
{
    /** @var list<Email> */
    private array $queue = [];

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function sendVerificationCode(User $user, #[\SensitiveParameter] string $code): void
    {
        $this->queue($user, 'Seu código de confirmação da Popularia', <<<TXT
            Olá, {$user->getDisplayName()}!

            Seu código para confirmar o e-mail na Popularia é:

                {$code}

            Ele vale por 24 horas. Se não foi você quem criou a conta, ignore esta mensagem.
            TXT);
    }

    public function sendAccountAlreadyExists(User $user): void
    {
        $this->queue($user, 'Tentativa de cadastro com o seu e-mail', <<<TXT
            Olá, {$user->getDisplayName()}!

            Alguém tentou criar uma conta na Popularia com este e-mail, mas você já tem uma.
            Se foi você, entre com sua senha ou use "Esqueci minha senha" no app.
            Se não foi você, não precisa fazer nada: sua conta continua protegida.
            TXT);
    }

    public function sendPasswordResetCode(User $user, #[\SensitiveParameter] string $code): void
    {
        $this->queue($user, 'Código para redefinir sua senha da Popularia', <<<TXT
            Olá, {$user->getDisplayName()}!

            Seu código para criar uma nova senha é:

                {$code}

            Ele vale por 30 minutos e só pode ser usado uma vez.
            Se você não pediu isso, ignore esta mensagem: sua senha continua a mesma.
            TXT);
    }

    public function sendPasswordChanged(User $user): void
    {
        $this->queue($user, 'Sua senha da Popularia foi alterada', <<<TXT
            Olá, {$user->getDisplayName()}!

            A senha da sua conta acabou de ser alterada e as outras sessões foram encerradas.
            Se não foi você, use "Esqueci minha senha" no app agora mesmo.
            TXT);
    }

    #[AsEventListener(KernelEvents::TERMINATE)]
    public function flush(?TerminateEvent $event = null): void
    {
        $queue = $this->queue;
        $this->queue = [];
        foreach ($queue as $email) {
            try {
                $this->mailer->send($email);
            } catch (TransportExceptionInterface $e) {
                // Sem o endereço no log: só o tipo de mensagem e o erro do transporte.
                $this->logger->error('Falha ao enviar e-mail da conta.', ['subject' => $email->getSubject(), 'error' => $e->getMessage()]);
            }
        }
    }

    public function reset(): void
    {
        $this->queue = [];
    }

    private function queue(User $user, string $subject, string $text): void
    {
        $this->queue[] = (new Email())
            ->to($user->getEmail())
            ->subject($subject)
            ->text($text);
    }
}
