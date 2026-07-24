# Introduction videos — recording plan

English-only screen recordings for **organization users** (email password flows) and **organization administrators** (logged-in management). No system users, global admins, or maintainers.

## Audience

| Audience | What they use | Chapters |
|---|---|---|
| Regular org user | Invite email → set password; forgot/reset email | 1–2 |
| Org admin | Login, org page, users, own password change | 3–7 |

Regular org users do **not** browse the app after login in these videos. They only arrive via email links (or the login page for “Forgot your password?”).

## Chapters (7 short recordings)

| # | Title | Target length |
|---|---|---|
| [1](chapter-1.md) | Your new account: setting your first password | 1–2 min |
| [2](chapter-2.md) | Forgot or reset your password | 1–2 min |
| [3](chapter-3.md) | Org admin: first login & change your own password | 1–2 min |
| [4](chapter-4.md) | Org admin: organization at a glance & editing company details | 1–2 min |
| [5](chapter-5.md) | Org admin: viewing and adding users | 2–3 min |
| [6](chapter-6.md) | Org admin: user status and the Manager role | 1–2 min |
| [7](chapter-7.md) | Org admin: helping users and removing accounts | 2–3 min |

Each chapter file has an **action script** (what to click) and **narration** (what to say), with numbered cues that match. Plain text for TTS is under [`narration/`](narration/).

## Out of scope

- System users of any role (`/manage/users/`, global admin, maintainer)
- Role management page, org deactivate/delete
- OIDC login, language switching, session-timeout / account-disabled errors
- Delete on the org page **Recent Users** table (known broken — use the full user list only)
- Regular-user post-login browsing

## Demo data prep

1. One demo organization with a known org admin account (password ready).
2. At least three org users who are **not** managers (so Delete and Manager toggles are free to demo).
3. One disposable user for Chapter 7 delete.
4. Email working: invite and reset links must arrive in a test inbox you can show on camera (Mailpit, real mailbox, or similar). Compose has no mail catcher by default — point SMTP at something you can open in the browser or Mail.app.
5. English UI only (leave the language chooser on English).
6. Do not show system accounts or the system users menu on camera.

## Browser / recording setup

1. Use a clean browser profile or window: hide bookmarks bar, zoom 100–110%.
2. Prefer a fixed window size (e.g. 1440×900) for every chapter.
3. Screen record with **Shift-Command-5** → Record Selected Window (browser only). Mute the mic; voiceover is added later.
4. Act slightly slower than the spoken pace so audio and video are easier to sync.
5. Combine video + AIFF/WAV per chapter in iMovie or QuickTime.

## Voiceover on macOS Tahoe

### Option A — `say` (recommended)

1. System Settings → Accessibility → Spoken Content → System Voice → Manage Voices.
2. Download an English premium voice (e.g. **Ava (Premium)**).
3. From `docs/videos/`:

```bash
./make-voiceover.sh
# or one chapter:
./make-voiceover.sh 1
```

4. Output lands in `audio/chapter-N.aiff`.

List voices: `say -v '?' | grep en_`. Override voice: `VOICE='Samantha' ./make-voiceover.sh`.

### Option B — Siri voice via Shortcuts

`say` cannot use Siri voices. To get a Siri voice:

1. Open **Shortcuts**.
2. New shortcut → add **Make Spoken Audio From Text**.
3. Paste the contents of `narration/chapter-N.txt`.
4. Choose an English Siri voice → run → save the audio file.
5. Repeat per chapter.

## Suggested recording order

1. Prep inbox + create a fresh invite (needed for Chapter 1).
2. Chapters 1 → 2 (email-driven; use a regular org user mailbox).
3. Chapters 3 → 7 as org admin (same session is fine; log out between chapters only if you want a clean start).
4. For Chapter 5, either use a new email address each take or delete the demo user afterward so re-records stay clean.
