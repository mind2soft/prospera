<?php

namespace App\Mail;

use App\Mail\Concerns\RetriesDelivery;
use App\Models\User;
use App\Settings\NotificationSettings;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * A financial profile share notification, sent when a profile owner invites
 * an email address (issue #39). One email per share event — re-issuing a
 * pending invite sends a new one. Two variants in one plain-text view:
 *
 * - The email is not linked to an account: the mail invites the recipient to
 *   create an account through the invite link (registration carries the
 *   invite, so the shared profile becomes their only profile).
 * - The email matches a registered account: the mail carries a **one-click
 *   accept link** that pre-authorizes the matching signed-in user; the mail
 *   states that ignoring the request declines the share.
 *
 * Queued, localized per recipient (`users.locale`, fallback `en`), and
 * gated through the `profile_invite` notification toggle — checked at
 * dispatch time and again at send time (inside the queued send), per
 * docs/notifications.md. Recipients without an account have no settings to
 * honor and always receive the invite. The invite key hash never appears in
 * any email. The links carry the raw invite key, which is never stored: the
 * queued payload is encrypted (ShouldBeEncrypted), so the jobs and
 * failed_jobs tables do not hold it in the clear either.
 */
class ProfileInvite extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, RetriesDelivery, SerializesModels;

    /** The longest inviter or profile name the subject line carries. */
    public const SUBJECT_NAME_MAX = 40;

    /**
     * Create a new message instance.
     *
     * @param  CarbonInterface  $expiresAt  when the invite stops working (now + 48h)
     * @param  string|null  $acceptUrl  the one-click accept link — null for
     *                                  recipients without an account
     * @param  int|null  $recipientUserId  the registered recipient, for the
     *                                     send-time notification gate — null
     *                                     when the email has no account yet
     */
    public function __construct(
        public readonly string $inviterName,
        public readonly string $inviterEmail,
        public readonly string $profileName,
        public readonly string $role,
        public readonly string $inviteUrl,
        public readonly ?string $acceptUrl = null,
        public readonly ?CarbonInterface $expiresAt = null,
        public readonly ?int $recipientUserId = null,
        string $locale = 'en',
    ) {
        $this->locale($locale);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $inviter = self::subjectText($this->inviterName);
        $profile = self::subjectText($this->profileName);

        return new Envelope(
            subject: $inviter === '' || $profile === ''
                ? __('mail.invite.subject_generic')
                : __('mail.invite.subject', ['inviter' => $inviter, 'profile' => $profile]),
        );
    }

    /**
     * A user-chosen name made safe for the subject line, the part of the
     * mail an inbox shows before it is opened (SEC-8): control characters
     * and line breaks are flattened, anything that looks like a link or a
     * domain is dropped (a name like "Claim your refund at evil.example"
     * must not turn the invite into a phishing lure), and the result is
     * capped at SUBJECT_NAME_MAX characters. The body states the full
     * names as plain text.
     */
    public static function subjectText(string $name): string
    {
        $text = preg_replace('/[\p{C}\s]+/u', ' ', $name) ?? '';
        $text = preg_replace('~(?:[a-z][a-z\d+.\-]*://|www\.)\S*~iu', ' ', $text) ?? '';
        $text = preg_replace('~[\p{L}\p{N}\-_@]+(?:\.[\p{L}\p{N}\-_]+)+\S*~u', ' ', $text) ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return Str::limit($text, self::SUBJECT_NAME_MAX, '…', preserveWords: true);
    }

    /**
     * Get the message content definition: a plain-text view, localized
     * through lang/{locale}/mail.php and rendered unescaped ({!! !!} — the
     * text part is not HTML, so `&` must not become `&amp;`).
     */
    public function content(): Content
    {
        $roleKey = 'mail.roles.'.$this->role;

        return new Content(
            text: 'mail.profile-invite',
            with: [
                'inviterName' => $this->inviterName,
                'inviterEmail' => $this->inviterEmail,
                'profileName' => $this->profileName,
                'role' => $this->role,
                'roleLabel' => Lang::has($roleKey) ? __($roleKey) : $this->role,
                'inviteUrl' => $this->inviteUrl,
                'acceptUrl' => $this->acceptUrl,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }

    /**
     * Deliver the message — unless the recipient opted out of profile
     * invitation emails. The gate is re-checked here, at send time, because
     * settings may change while the message sits in the queue.
     */
    public function send($mailer)
    {
        if ($this->recipientUserId !== null) {
            $recipient = User::find($this->recipientUserId);

            if ($recipient === null
                || ! $recipient->wantsNotification(NotificationSettings::PROFILE_INVITE)) {
                return null;
            }
        }

        return parent::send($mailer);
    }
}
