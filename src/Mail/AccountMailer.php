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
        $name = trim($user->getDisplayName());
        $firstName = '' !== $name ? explode(' ', $name)[0] : $name;
        $this->queue(
            $user,
            'Seu código da Popularia: '.$code,
            'emails/verification.html.twig',
            ['nome' => $firstName, 'codigo' => $code],
            <<<TXT
            Oi, {$firstName}!

            Pra terminar seu cadastro na Popularia, digite este código no app:

            {$code}

            Vale por 24 horas e só pode ser usado uma vez.

            Não criou uma conta? Pode ignorar este e-mail: nenhuma conta foi ativada.

            --
            Popularia · um lugar nosso
            Suporte: suporte@popularia.com.br · https://popularia.com.br/suporte
            TXT,
        );
    }

    public function sendAccountAlreadyExists(User $user): void
    {
        $name = trim($user->getDisplayName());
        $firstName = '' !== $name ? explode(' ', $name)[0] : $name;
        $this->queue(
            $user,
            'Você já tem conta na Popularia',
            'emails/account_already_exists.html.twig',
            ['nome' => $firstName],
            <<<TXT
            Oi, {$firstName}!

            Alguém tentou criar uma conta na Popularia com este e-mail, mas ele já está cadastrado. Nada foi alterado na sua conta.

            FOI VOCÊ? Entre com sua senha no app. Esqueceu? Use "Esqueci minha senha".
            NÃO FOI VOCÊ? Não precisa fazer nada. Sua conta continua protegida.

            Por segurança, nunca compartilhe sua senha ou códigos com ninguém.

            --
            Popularia · um lugar nosso
            Suporte: suporte@popularia.com.br · https://popularia.com.br/suporte
            TXT,
        );
    }

    public function sendPasswordResetCode(User $user, #[\SensitiveParameter] string $code): void
    {
        $name = trim($user->getDisplayName());
        $firstName = '' !== $name ? explode(' ', $name)[0] : $name;
        $this->queue(
            $user,
            'Código pra criar uma nova senha: '.$code,
            'emails/password_reset.html.twig',
            ['nome' => $firstName, 'codigo' => $code],
            <<<TXT
            Oi, {$firstName}!

            Recebemos um pedido pra criar uma nova senha na sua conta. Digite este código no app:

            {$code}

            Vale por 30 minutos e só pode ser usado uma vez.

            Não passe este código pra ninguém. A equipe da Popularia nunca pede código, senha ou token, nem por e-mail, nem por mensagem. Se alguém pedir, não é a gente.

            Não pediu uma senha nova? Sua conta continua segura. É só ignorar este e-mail.

            --
            Popularia · um lugar nosso
            Suporte: suporte@popularia.com.br · https://popularia.com.br/suporte
            TXT,
        );
    }

    public function sendPasswordChanged(User $user): void
    {
        $name = trim($user->getDisplayName());
        $firstName = '' !== $name ? explode(' ', $name)[0] : $name;
        $this->queue(
            $user,
            'Sua senha da Popularia foi alterada',
            'emails/password_changed.html.twig',
            ['nome' => $firstName],
            <<<TXT
            Oi, {$firstName}!

            A senha da sua conta na Popularia acabou de ser alterada. Por segurança, encerramos todas as outras sessões abertas.

            NÃO FOI VOCÊ?
            Abra o app agora e use "Esqueci minha senha" pra recuperar o acesso.
            Se não conseguir entrar, escreva pra suporte@popularia.com.br.

            Foi você? Então tá tudo certo, não precisa fazer nada.

            --
            Popularia · um lugar nosso
            Suporte: suporte@popularia.com.br · https://popularia.com.br/suporte
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
