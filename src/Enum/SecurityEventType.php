<?php

declare(strict_types=1);

namespace App\Enum;

/** Eventos guardados no registro de segurança (tabela security_event, com IP e retenção limitada). */
enum SecurityEventType: string
{
    case AccountRegistered = 'account_registered';
    case RegistrationWithExistingEmail = 'registration_with_existing_email';
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case LoginBlockedAccountStatus = 'login_blocked_account_status';
    case SessionRevoked = 'session_revoked';
    case AllSessionsRevoked = 'all_sessions_revoked';
    case RefreshTokenReuseDetected = 'refresh_token_reuse_detected';
    case EmailVerified = 'email_verified';
    case EmailVerificationFailed = 'email_verification_failed';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetFailed = 'password_reset_failed';
    case PasswordResetCompleted = 'password_reset_completed';
    case PasswordChanged = 'password_changed';
    case RateLimitExceeded = 'rate_limit_exceeded';
    case AccountStatusChanged = 'account_status_changed';
}
