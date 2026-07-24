# Chapter 7 — Org admin: helping users and removing accounts

**Audience:** Organization administrators.  
**Length:** about 2–3 minutes.  
**Start:** Logged in on the full user list `/manage/organizations/{uuid}/users/`.  
**Prep:** A non-manager user for reset and edit; a disposable non-manager for delete. SMTP ready for the reset email.

**Do not** demo Delete on the organization page **Recent Users** table (broken). Always delete from this full list.

---

## Action script

| Cue | Do this |
|---|---|
| 1 | On a non-manager row, click **New password**. |
| 2 | In **Reset Credentials for …**, leave **Email password reset link (user sets a new password)** checked (password fields hide). Click **Reset**. |
| 3 | Show the success / “reset link sent” confirmation. |
| 4 | Click **Edit** on a user. In **Edit User**, change e.g. last name or display name. Click **Save changes**. |
| 5 | On a disposable non-manager, click **Delete**. |
| 6 | Confirm in the **Delete User Account** modal with **Delete Account**. |
| 7 | Show the user gone from the list. Optional: mention that managers must have Manager turned off before delete. |

---

## Narration

**Cue 1.** When someone cannot get back into their account, open New password on their row.

**Cue 2.** Prefer Email password reset link. That sends them the same kind of email as forgot password — they choose a new password themselves. Click Reset.

**Cue 3.** Confirm the link was sent. They finish the flow from their inbox, as in Chapter 2.

**Cue 4.** Use Edit to fix a name or email. Save changes when you are done. Leave the optional password fields empty unless you truly need to set a password by hand.

**Cue 5.** To remove someone permanently, use Delete on the full user list — not the Recent Users shortcuts on the organization overview.

**Cue 6.** Read the warning, then confirm Delete Account. This cannot be undone.

**Cue 7.** The account is gone from your organization. If Delete is disabled, they are still a manager — turn Manager off first, then delete.
