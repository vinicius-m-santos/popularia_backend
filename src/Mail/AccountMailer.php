<?php

declare(strict_types=1);

namespace App\Mail;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
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
        $name = $user->getDisplayName();
        $this->queue(
            $user,
            'Seu código de confirmação da Popularia',
            'emails/verification.html.twig',
            ['name' => $name, 'code' => $code],
            <<<TXT
            Olá, {$name}!

            Seu código para confirmar o e-mail na Popularia é:

                {$code}

            Ele vale por 24 horas. Se não foi você quem criou a conta, ignore esta mensagem.
            TXT,
        );
    }

    public function sendAccountAlreadyExists(User $user): void
    {
        $name = $user->getDisplayName();
        $this->queue(
            $user,
            'Tentativa de cadastro com o seu e-mail',
            'emails/account_already_exists.html.twig',
            ['name' => $name],
            <<<TXT
            Olá, {$name}!

            Alguém tentou criar uma conta na Popularia com este e-mail, mas você já tem uma.
            Se foi você, entre com sua senha ou use "Esqueci minha senha" no app.
            Se não foi você, não precisa fazer nada: sua conta continua protegida.
            TXT,
        );
    }

    public function sendPasswordResetCode(User $user, #[\SensitiveParameter] string $code): void
    {
        $name = $user->getDisplayName();
        $this->queue(
            $user,
            'Código para redefinir sua senha da Popularia',
            'emails/password_reset.html.twig',
            ['name' => $name, 'code' => $code],
            <<<TXT
            Olá, {$name}!

            Seu código para criar uma nova senha é:

                {$code}

            Ele vale por 30 minutos e só pode ser usado uma vez.
            Se você não pediu isso, ignore esta mensagem: sua senha continua a mesma.
            TXT,
        );
    }

    public function sendPasswordChanged(User $user): void
    {
        $name = $user->getDisplayName();
        $this->queue(
            $user,
            'Sua senha da Popularia foi alterada',
            'emails/password_changed.html.twig',
            ['name' => $name],
            <<<TXT
            Olá, {$name}!

            A senha da sua conta acabou de ser alterada e as outras sessões foram encerradas.
            Se não foi você, use "Esqueci minha senha" no app agora mesmo.
            TXT,
        );
    }

    public function sendSupportMessage(User $user, string $message): void
    {
        $this->queue[] = (new Email())
            ->to('vinicius.m.dos.s@gmail.com')
            ->replyTo($user->getEmail())
            ->subject('Mensagem pelo app Popularia')
            ->text("Conta: {$user->getEmail()} (@{$user->getHandle()})\n\n{$message}");
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
                $this->logger->error('Falha ao enviar e-mail da conta.', ['subject' => $email->getSubject(), 'exception_class' => $e::class]);
            }
        }
    }

    public function reset(): void
    {
        $this->queue = [];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function queue(User $user, string $subject, string $template, array $context, string $text): void
    {
        $this->queue[] = (new TemplatedEmail())
            ->to($user->getEmail())
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($context)
            ->text($text);
    }
}
