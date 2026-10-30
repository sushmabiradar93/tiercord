=== TierCord – Discord Access for Paid Memberships Pro ===
Contributors: sushma4393
Tags: discord, paid memberships pro, membership, discord roles, email notifications
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give Paid Memberships Pro members the right Discord roles and channels by level, pause access when membership ends, and email members.

== Description ==

TierCord connects Paid Memberships Pro to Discord. Choose which Discord roles each membership level gets – in one server or several – and Discord's own channel permissions decide which channels each role can see. Members connect their Discord account once and get the right roles automatically. When a membership expires, is cancelled or downgraded, the roles are taken away. When it is renewed or upgraded, they come back on their own.

It also includes an optional email module that keeps members informed by level: new posts, announcements, and changes to their Discord access.

= Discord access =

* **Different channels for different levels – in one server.** Create a role per tier (for example Basic, Pro and Premium), allow each private channel for the right roles in Discord, and tick which roles each level gets in a simple grid.
* **Or several servers.** The same grid works across servers: Basic → Server A, Pro → A + B, Premium → A + B + C – or any mix of servers and roles.
* **One-click joining.** Members click "Connect Discord" on their Membership Account page. The bot adds them to the server with their roles already applied – no invite links to share or leak.
* **Upgrades, downgrades and pauses handled.** Upgrades add roles, downgrades remove only the roles the new level doesn't include, and on expiry or cancellation all TierCord roles are removed (or, if you prefer, the member is removed from the server). Renewal restores them instantly.
* **Your other roles are safe.** TierCord only adds or removes the roles you tick. Moderator, booster and other hand-given roles are never touched.
* **See who can see what.** The settings screen reads your Discord channel permissions and shows which roles can see each channel, so you can spot a misconfigured channel without leaving WordPress.
* **Supports multiple memberships per user** (Paid Memberships Pro 3.x): access is the combination of all active levels.
* **One Discord account per member account,** so a subscription can't be shared.
* **Safety net:** a daily background re-sync, plus "Sync all now" and per-member Sync/Unlink buttons.
* **Clear admin tools:** server and role pickers loaded straight from Discord, warnings when the bot's role is too low, a members table with per-server status, and an activity log.

= Email notifications (optional module) =

* **New posts by level.** When you publish, members of the levels that can read the post are emailed (based on the post's or category's "Require Membership" setting). A per-post box lets you turn it off, pick levels by hand, or email again.
* **Announcements.** Write an announcement in WordPress and send it to chosen levels, all members, or everyone with access to a specific Discord server. Send yourself a test first.
* **Discord announcements by email (optional).** Messages posted in your Discord announcement channels can be emailed only to the members whose roles can see that channel – a post in #premium-signals reaches Premium members only. This needs a Discord bot that you run yourself to forward each message to TierCord (see "Discord → email bridge" below).
* **Announcements by role.** Send an announcement written in WordPress to members of chosen levels, chosen Discord roles, or a whole server.
* **Access emails.** "You now have access to Premium", "Your access to Premium is paused" (with a renewal link) and "Please reconnect Discord".
* **Respectful sending.** Emails are queued and sent in the background at a speed you choose. Every post and announcement email has an unsubscribe link and one-click unsubscribe headers, and members can manage their preferences on their account page.
* **Editable templates** with placeholders such as `{first_name}`, `{post_title}` and `{button:Read the post|{post_url}}`.

= Shortcodes =

* `[tiercord]` – the "Connect Discord" box with the member's servers (added to the Membership Account page automatically).
* `[tiercord_email_preferences]` – the member's email preferences (also added to the Membership Account page automatically).

If you place either shortcode on the Membership Account page yourself, TierCord does not add it a second time.

= Discord → email bridge =

To email Discord announcements, turn on the bridge under **Memberships → TierCord Emails → Settings**, generate a secret, and have your own Discord bot send each new message in your announcement channels to:

* `POST /wp-json/tiercord/v1/announce` with the header `X-TierCord-Secret: <your secret>` and a JSON body:
  * required: `guild_id`, `channel_id`, `message_id`
  * optional: `content` (the message text, Discord markdown), `channel_name`, `author`, `url` (link to the message), `attachments` (array of file URLs)
* `GET /wp-json/tiercord/v1/ping` with the same header checks the connection and returns the servers and allowed channels.

TierCord emails only the members whose roles can see that channel, ignores repeated message IDs, and accepts only servers it manages (and, if you list them, only the allowed channel IDs).

= Requirements =

* [Paid Memberships Pro](https://wordpress.org/plugins/paid-memberships-pro/) (free).
* A free Discord application with a bot (step-by-step instructions are shown in the plugin).
* For reliable email delivery, an SMTP plugin is recommended.

TierCord is an independent project. It is not affiliated with, endorsed by or sponsored by Discord Inc. or Stranger Studios (Paid Memberships Pro). "Discord" and "Paid Memberships Pro" are trademarks of their respective owners.

== External services ==

This plugin connects to the **Discord API** (`https://discord.com/api/`), a service provided by Discord Inc., because managing Discord server access is its purpose. Nothing is sent to Discord until you enter your own Discord application credentials, and member data is only sent after the member clicks "Connect Discord".

What is sent, and when:

* **When a member clicks "Connect Discord":** the member is sent to Discord's authorisation page. After they approve, the plugin exchanges the authorisation code for an access token and reads the member's Discord user ID, username and avatar ID (scopes `identify` and `guilds.join`). The Discord user ID, username, avatar ID and the encrypted OAuth tokens are stored in your WordPress database.
* **When a member's membership changes (checkout, renewal, upgrade, downgrade, cancellation, expiry), once a day as a safety net, and when an admin clicks "Sync":** the member's Discord user ID, the server (guild) IDs and role IDs you configured are sent to Discord to read the member's current roles in that server, add the member to the server, give or remove the roles you configured, or remove the member from the server.
* **When an admin opens the settings screens:** the bot token is used to list the servers the bot is in, the roles in those servers and the channels with their permission settings (to show which roles can see which channel).
* **When your bot forwards an announcement to the optional email bridge:** the channel list and permissions of that server are read to work out which members may receive the email.
* **When a member disconnects, or an admin unlinks a member:** the roles TierCord gave are removed and the OAuth token is revoked with Discord.

Discord's terms and privacy policy apply: [Discord Terms of Service](https://discord.com/terms), [Discord Privacy Policy](https://discord.com/privacy), [Discord Developer Terms of Service](https://discord.com/developers/docs/policies-and-agreements/developer-terms-of-service).

**Email module:** emails are sent through your own site with WordPress's `wp_mail()`; TierCord does not use an external email service. If you use the optional email bridge, your own Discord bot sends announcement messages to your site's REST endpoint `/wp-json/tiercord/v1/announce`, protected by a secret key. Images attached to Discord announcements are linked from Discord's CDN (`cdn.discordapp.com`) in the emails.

== Installation ==

1. Install and activate **Paid Memberships Pro**.
2. Download TierCord from [GitHub](https://github.com/sushmabiradar93/tiercord) (**Code → Download ZIP**), then in WordPress go to **Plugins → Add New → Upload Plugin**, upload the zip and activate it.
3. Go to **Memberships → TierCord → Connection** and follow the on-screen steps:
   1. In the [Discord Developer Portal](https://discord.com/developers/applications), create an application.
   2. On the **OAuth2** page, copy the Client ID and Client Secret into TierCord, and add the Redirect URL shown in TierCord.
   3. On the **Bot** page, reset and copy the bot token into TierCord, and turn off "Public Bot".
   4. Save, then use **Invite bot to a server** once for each of your servers.
   5. In each Discord server, open **Server Settings → Roles**, create a role per tier (for example Basic, Pro, Premium) and drag the bot's role above them.
4. In Discord, edit each private channel or category → **Permissions**: turn off "View Channel" for @everyone and turn it on for the roles that should see it.
5. Go to **Memberships → TierCord → Roles & Levels**: tick your server and the roles TierCord should manage, save, then tick which roles each membership level gets. Check the "Who can see which channel" table.
6. Optional: configure emails under **Memberships → TierCord Emails**.

== Frequently Asked Questions ==

= Can different levels see different channels in the same server? =

Yes – that is the most common setup. Create a role per tier in Discord, set each private channel so only the right roles can view it, and tick which roles each level gets. The "Who can see which channel" table on the Roles & Levels screen shows the result.

= How do higher levels also see the lower tiers' channels? =

Either tick the lower roles for the higher level too (Premium gets Basic + Pro + Premium), or give one role per level and allow the higher roles on the lower channels in Discord. Both work.

= Will TierCord remove roles I gave people by hand? =

No. It only adds or removes the roles you tick under "Roles TierCord hands out". All other roles are left alone.

= What happens when a membership expires and is renewed later? =

By default the member keeps their place in the server but loses their TierCord roles, so the gated channels are hidden. When they renew, the roles are given back automatically. You can instead choose to remove members from the server; they are added back automatically on renewal.

= Do members need an invite link? =

No. When a member connects Discord, the bot adds them to the servers their level includes.

= The log says "Missing Permissions". What should I do? =

In that Discord server, open **Server Settings → Roles** and drag the bot's role above every role TierCord hands out. Also check the bot has the "Manage Roles" permission.

= A member shows "needs reconnect". =

Their Discord authorisation expired before they could be added to a server. They just click "Reconnect Discord" on their account page. Members who are already in a server are not affected.

= Do I need to run a Discord bot for the emails? =

No. A bot of your own is only needed if you want messages posted in Discord to be emailed (see "Discord → email bridge"). New-post emails, announcements written in WordPress and access emails all work without it.

= Can members stop receiving emails? =

Yes. Every new-post and announcement email has an unsubscribe link, and members can change their preferences on their account page. Emails about their Discord access are always sent.

= Can I keep my Discord secrets out of the database? =

Yes. Define `TIERCORD_CLIENT_ID`, `TIERCORD_CLIENT_SECRET` and `TIERCORD_BOT_TOKEN` in `wp-config.php`.

= Does it work with WP-Cron disabled? =

Background jobs (daily re-sync, email batches, delayed post emails) use WP-Cron. If you disable it, set up a real cron job that calls `wp-cron.php`.

= Where can I report bugs or contribute? =

On [GitHub](https://github.com/sushmabiradar93/tiercord/issues).

== Changelog ==

= 1.0.1 =
* Fix: the "Connect Discord" and email-preference boxes appeared twice on the Membership Account page when the page already contained the `[tiercord]` or `[tiercord_email_preferences]` shortcode.
* Fix: the "Generate new" secret confirmation showed a stray backslash.
* The Discord → email bridge is now documented in the readme (endpoint, header and fields) for use with your own bot; removed links to a bot download that is not available.
* Installation instructions now point to GitHub.

= 1.0.0 =
* Initial release: Discord roles and channels by Paid Memberships Pro level (one server or several), automatic pause and restore, channel visibility preview, and the email notifications module.

== Upgrade Notice ==

= 1.0.1 =
Fixes the Discord and email-preference boxes showing twice on the Membership Account page.

= 1.0.0 =
First release.
