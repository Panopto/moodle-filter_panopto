# Panopto filter

Moodle removes iframes from content written by users without trusted text permissions, such as students' assignment online text submissions and forum posts. This filter lets Panopto videos inserted with the [Panopto Tiny button](https://github.com/Panopto/moodle-tiny_panoptoltibutton) (`tiny_panoptoltibutton`) display in that content.

Moodle removes iframes only when it cleans the content to display it. The filter replaces each Panopto video with a link before Moodle cleans the content, and turns the link back into the video afterwards. Where the filter is on, the Tiny button (version 2026102900 or later) also saves videos as these links, so they survive when a student edits their forum post. Only videos that launch through this site's Panopto launch page with the course's Panopto LTI tool are shown. Other videos, and videos in notification emails, are shown as a link with the video title.

Videos added before the filter was turned on are shown too, whichever version of the Tiny button inserted them. Videos always launch in the course showing them, so content copied, imported or restored into another course keeps working.

On the assignment **Submissions** page, videos are shown as links unless **Embed videos on the Submissions page** is enabled in the filter settings.

## Who it is for

- **Students and teachers** see Panopto videos, not empty space, in forum posts, online text submissions and other content they add with the Panopto Tiny button.
- **Administrators** install the plugin, turn the filter on, and choose whether videos are embedded on the Submissions page.

## Requirements

- A supported **Moodle** version. Each [GitHub release](https://github.com/Panopto/moodle-filter_panopto/releases) lists the Moodle versions that zip supports — check those notes and pick the release that matches your site.
- The [Panopto block](https://github.com/Panopto/Moodle-2.0-Plugin-for-Panopto)
- The [Panopto Tiny button](https://github.com/Panopto/moodle-tiny_panoptoltibutton)
- Moodle’s **External tool (LTI)** module and a Panopto LTI tool

## Installation

1. Download a release from [GitHub](https://github.com/Panopto/moodle-filter_panopto/releases).
2. In Moodle, go to **Site administration → Plugins → Install plugins**.
3. Drop the zip onto the install page and complete the upgrade.
4. Go to **Site administration → Plugins → Filters → Manage filters** and set **Panopto videos** to **On**.

## Settings

**Site administration → Plugins → Filters → Panopto videos**

- **Embed videos on the Submissions page**: off by default, so videos in online text submissions are shown as links with the video title on the assignment Submissions page. Turn it on to embed them there too. Each embedded video launches Panopto, so the page can be slow when there are many submissions.

## Known limitations

These apply to videos inserted with Tiny button versions before 2026102900:

- They have no title, so they are shown with the title "Panopto video".
- They are saved as iframes. When a user without trusted text permissions opens a forum post to edit it, Moodle cleans the post first, which removes those videos. They have to be inserted again before saving.

## For developers

### Tests

Run PHPUnit from the Moodle root:

```
vendor/bin/phpunit --testsuite filter_panopto_testsuite
```

### Releasing

1. Bump `$plugin->version` in `version.php`.
2. Zip the plugin (omit `.git` and `node_modules`) and install it as a new release.
