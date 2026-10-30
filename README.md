# TierCord – Discord Access for Paid Memberships Pro

A WordPress plugin that gives [Paid Memberships Pro](https://wordpress.org/plugins/paid-memberships-pro/) members the right Discord roles and channels for their membership level, pauses access when a membership ends, and emails members about new posts, announcements and access changes.

**Version:** 1.0.1 · **Requires:** WordPress 6.5+, PHP 7.4+, Paid Memberships Pro · **Tested up to:** WordPress 7.1 · **License:** GPLv2 or later

## Features

**Discord access**

- Choose which Discord roles each membership level gets, in one server or several. Discord's own channel permissions decide which channels each role can see.
- Members click **Connect Discord** on their Membership Account page; the bot adds them to the server with their roles already applied (no invite links).
- Upgrades add roles, downgrades remove only the roles the new level doesn't include, and expiry or cancellation removes all TierCord roles (or removes the member from the server). Renewal restores them.
- Only the roles you tick are ever changed; moderator, booster and other hand-given roles are left alone.
- A "Who can see which channel" table, server and role pickers loaded from Discord, warnings when the bot's role is too low, a members table and an activity log.
- Multiple memberships per user (Paid Memberships Pro 3.x), one Discord account per member account, and a daily re-sync as a safety net.

**Email notifications (optional module)**

- New-post emails to the levels that can read the post.
- Announcements written in WordPress, sent to chosen levels, Discord roles or a whole server.
- Access emails: access granted, access paused (with a renewal link), and "please reconnect Discord".
- Queued background sending, unsubscribe links and one-click unsubscribe headers, member email preferences, and editable templates with placeholders.
- Optional **Discord → email bridge**: messages from your Discord announcement channels emailed only to the members whose roles can see that channel (needs a bot you run yourself; see below).

## Installation

1. Install and activate **Paid Memberships Pro**.
2. Download this repository (**Code → Download ZIP**). In WordPress go to **Plugins → Add New → Upload Plugin**, upload the zip and activate **TierCord**.
3. Go to **Memberships → TierCord → Connection** and follow the on-screen steps to create a Discord application and bot, add the redirect URL, and invite the bot to your servers.
4. In each Discord server, create a role per tier and drag the bot's role above them. Set each private channel so only the right roles can view it.
5. Under **Memberships → TierCord → Roles & Levels**, tick the roles TierCord manages and which roles each level gets.
6. Optional: configure emails under **Memberships → TierCord Emails**.

Discord secrets can be kept out of the database by defining `TIERCORD_CLIENT_ID`, `TIERCORD_CLIENT_SECRET` and `TIERCORD_BOT_TOKEN` in `wp-config.php`.

## Shortcodes

| Shortcode | Shows |
|---|---|
| `[tiercord]` | The "Connect Discord" box with the member's servers |
| `[tiercord_email_preferences]` | The member's email preferences |

Both are added to the Membership Account page automatically; if you place them on that page yourself, they are not added twice.

## Discord → email bridge

Turn on the bridge under **Memberships → TierCord Emails → Settings** and generate a secret. Your own Discord bot then sends each new announcement message to your site:

```http
POST /wp-json/tiercord/v1/announce
X-TierCord-Secret: <your secret>
Content-Type: application/json

{
  "guild_id": "123456789012345678",
  "channel_id": "234567890123456789",
  "message_id": "345678901234567890",
  "content": "Message text (Discord markdown)",
  "channel_name": "announcements",
  "author": "Name",
  "url": "https://discord.com/channels/…",
  "attachments": ["https://cdn.discordapp.com/…/chart.png"]
}
```

`guild_id`, `channel_id` and `message_id` are required; the rest are optional (at least `content` or one attachment). TierCord emails only the members whose roles can see that channel, ignores repeated message IDs, and accepts only servers it manages and, if listed, the allowed channel IDs.

`GET /wp-json/tiercord/v1/ping` with the same header checks the connection and returns the managed servers and allowed channels.

## External services

TierCord talks to the [Discord API](https://discord.com/developers/docs) to link member accounts (OAuth scopes `identify` and `guilds.join`), add members to servers, and give or remove the roles you configured. Nothing is sent until you enter your own Discord application credentials, and member data is only sent after the member clicks **Connect Discord**. Emails are sent through your own site with `wp_mail()`. Full details are in [readme.txt](readme.txt).

## Changelog

See the Changelog section of [readme.txt](readme.txt).

## Support

Report bugs and suggestions in [Issues](https://github.com/sushmabiradar93/tiercord/issues).

---

TierCord is an independent project. It is not affiliated with, endorsed by or sponsored by Discord Inc. or Stranger Studios (Paid Memberships Pro). "Discord" and "Paid Memberships Pro" are trademarks of their respective owners.
