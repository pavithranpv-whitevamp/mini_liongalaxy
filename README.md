# Mini Lion Galaxy

Website for the Mini Lion Galaxy YouTube channel ([@miniliongalaxy](https://www.youtube.com/@miniliongalaxy)): ABCs, stories and fun learning for little stars.

A plain static site (HTML, CSS and JavaScript). There is no build step.

## Pages

- `index.html`: Home (hero, explore collection, why Mini Lion Galaxy, footer)
- `explore.html`: the channel's videos, loaded automatically
- `reach-out.html`: contact cards and message form

## Video autopilot

The Explore page lists the channel's videos and Shorts and picks up new uploads by itself.

- On hosts with PHP, `videos.php` reads the channel and returns the list, re-checked every 15 minutes.
- On hosts without PHP (such as Cloudflare Pages), `script.js` reads the list from the copy at `https://pvcreationz.online/mini_liongalaxy/videos.php`.
- If neither works, the page shows YouTube's own playlist player.

The feed list is set in `script.js` (`MLG.feeds`).

## Settings

In `script.js`:

- `contactEmail`: the email the Reach out form opens. Empty for now, so the form points visitors to YouTube.

## Deploying to Cloudflare Pages

Connect this repository in Cloudflare (Workers & Pages, then Create, Pages, Connect to Git). Leave the build command empty and set the output directory to `/`. Every push to `main` redeploys the site.
