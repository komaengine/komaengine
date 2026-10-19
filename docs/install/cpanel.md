# Install on cPanel

This guide installs the KomaEngine release ZIP on hosting that uses
**cPanel**. You do everything in cPanel, plus a few commands in cPanel's
**Terminal**. No Composer, Node, or Git is needed.

Plan on about 30 minutes. The examples use the domain `manga.example.com` and
the cPanel account `acct`; replace them with yours everywhere.

> **Do not unpack the ZIP into `public_html`.** Everything inside
> `public_html` can be reached from the web, so that would expose the whole
> application, including your settings, logs, and manga files. This guide puts
> KomaEngine **next to** `public_html` and connects only its `public` folder to
> the web.

The menu names below are from cPanel's standard **Jupiter** look. If your cPanel
looks different (for example the newer **Meridian** look), the same tools
exist; use the search box at the top to find them by name.

## Before you start

You need:

- **Terminal access.** In cPanel, look for **Terminal** in the **Advanced**
  section. If it is missing, ask your host to enable shell (SSH) access for
  your account. Without it, KomaEngine cannot be installed from the ZIP.
- **A subdomain or an extra (addon) domain for KomaEngine**, such as
  `manga.example.com`. cPanel does not let you change where your account's
  **main** domain points, so KomaEngine goes on its own subdomain or domain.
  Your main site stays as it is. See
  [step 4](#4-create-the-domain-and-connect-it-to-komaenginepublic).
- **PHP 8.3, 8.4, or 8.5** offered by your host. A new cPanel server offers only
  PHP 8.1 and 8.2 until the host installs newer versions, so check step 5
  early and ask your host to install PHP 8.3 if it is missing.
- **The release ZIP and its SHA-256** from the
  [download page](https://komaengine.com/download/).

cPanel's menus vary a little between hosts, and some hosts hide tools. Use the
search box at the top of cPanel to find a tool by name. Each step says what to
ask your host for if a tool is missing.

### What the files will look like

```text
/home/acct/                        your account's home folder
├── public_html/                   your main site (left as it is)
│   └── manga.example.com  ->  /home/acct/komaengine/public    a link, made in step 4
└── komaengine/                    KomaEngine: private
    ├── .env                       settings, database password, secret key
    ├── storage/                   manga, uploads, logs
    └── public/                    the only folder the web can reach
```

cPanel requires each domain's folder to be inside `public_html`. The new
domain's folder there is a **link** to `komaengine/public`, so the domain
serves only that folder and the rest of KomaEngine stays outside
`public_html`.

## 1. Check the download on your computer

Download the ZIP from the [download page](https://komaengine.com/download/)
and check that its SHA-256 matches the one listed there:

| Computer | Command |
|---|---|
| macOS (Terminal) | `shasum -a 256 ~/Downloads/komaengine-YYYYMMDD-HHMMSS.zip` |
| Windows (PowerShell) | `Get-FileHash $HOME\Downloads\komaengine-YYYYMMDD-HHMMSS.zip -Algorithm SHA256` |
| Linux | `sha256sum ~/Downloads/komaengine-YYYYMMDD-HHMMSS.zip` |

Use the real file name. The long value printed must match the published
SHA-256 (Windows shows it in capitals, which is fine). If it does not match,
download the file again.

## 2. Create the database

1. In cPanel, open **Manage My Databases** (called **MySQL® Databases** on
   some hosts), in the **Databases** section.
2. Under **Create New Database**, enter a name such as `komaengine` and choose
   **Create Database**. cPanel adds your account name in front, so the full
   name becomes something like `acct_komaengine`.
3. Under **Add New User**, enter a user name such as `koma` and a strong
   password (**Password Generator** can make one), then choose
   **Create User**. The full user name becomes something like `acct_koma`.
4. Under **Add User To Database**, select that user and that database, choose
   **Add**, tick **ALL PRIVILEGES**, and choose **Make Changes**.

Write down the **full** database name, the **full** user name, and the
password. You need them in step 7 and again in the installer.

## 3. Upload and extract the ZIP

1. Open **File Manager** (in **Files**). You start in your home folder
   (`/home/acct`), which contains `public_html`. Stay here; do not open
   `public_html`.
2. Choose **Upload**, select the ZIP, and wait until it shows 100%. Then go
   back to File Manager.
3. Select the ZIP, choose **Extract**, keep the suggested folder (your home
   folder), and choose **Extract File(s)**.
4. A new `komaengine` folder appears next to `public_html`. Open it and check
   that it contains `artisan`, `public`, and `storage`.
5. Go back to the home folder and delete the ZIP; it is no longer needed.

File Manager hides files whose names start with a dot, such as `.env`. To see
them, choose **Settings** (top right), tick **Show Hidden Files (dotfiles)**,
and choose **Save**.

If the upload is refused because the file is too large, upload it with an SFTP
program such as FileZilla or Cyberduck instead, then extract it in File
Manager.

## 4. Create the domain and connect it to `komaengine/public`

This takes two parts: cPanel creates the domain and its folder inside
`public_html`, then you replace that folder with a link to KomaEngine's
`public` folder.

**Create the domain:**

1. Open **Domains** (in the **Domains** section) and choose
   **Create A New Domain**.
2. Enter the domain, for example `manga.example.com`.
3. Untick **Share document root** if it is shown. cPanel fills in a folder
   such as `public_html/manga.example.com`. Keep it, and write it down.
4. Choose **Submit**.

**Connect the folder to KomaEngine.** Open **Terminal** (in **Advanced**). If it
asks, choose **I understand and want to proceed**. Run these two commands,
using the folder name from the step above:

```bash
mv ~/public_html/manga.example.com ~/manga.example.com.original
ln -s ~/komaengine/public ~/public_html/manga.example.com
```

The first command moves the starter folder cPanel created out of the way (you
can delete `~/manga.example.com.original` once the site works). The second
puts a link in its place. Check the result:

```bash
ls -l ~/public_html/ | grep manga
```

It should show `manga.example.com -> /home/acct/komaengine/public`.

Your main site is not affected. The KomaEngine site is also reachable as
`https://your-main-domain/manga.example.com/`, but that address shows only the
same public folder; the application, settings, and storage stay unreachable.

Some hosts allow document roots outside `public_html`. On those, you can
instead choose **Manage** next to the domain in **Domains**, set
**New Document Root** to `komaengine/public`, and choose **Update**; no link is
needed. If cPanel refuses that folder, use the link as above.

**Your account's main domain:** cPanel does not let you change what it serves,
which stays `public_html`. Use a subdomain or another domain as above. Do not
move KomaEngine's files into `public_html`, and do not replace `public_html`
itself with a link.

### Turn on HTTPS

Most cPanel hosts issue free certificates automatically with **AutoSSL**,
usually within a few minutes to a few hours of creating a domain. Open
**SSL/TLS Status** (in **Security**) to see whether the new domain has a valid
certificate. If the page offers **Run AutoSSL**, you can start it right away.
If your host uses another tool (for example **Let's Encrypt™ SSL**), use it to
issue a certificate for the domain.

When the certificate is in place, open **Domains** and turn on
**Force HTTPS Redirect** for the domain.

Opening `https://manga.example.com/` at this point shows an error page; that is
expected until step 8.

## 5. Choose PHP 8.3 or newer

Hosts offer one of two tools. Use the one you have.

**MultiPHP Manager** (in **Software**):

1. Tick the domain you created for KomaEngine in step 4.
2. Choose **PHP 8.3** (`ea-php83`), 8.4, or 8.5 from the **PHP Version** list
   and choose **Apply**. If no 8.3 or newer version is listed, ask your host
   to install one.

Then open **MultiPHP INI Editor** (also in **Software**), choose
**Editor Mode** or **Basic Mode**, select the same domain, and set:

| Setting | Value |
|---|---|
| `memory_limit` | `512M` |
| `max_execution_time` | `3600` |
| `upload_max_filesize` | `512M` |
| `post_max_size` | `520M` |

**Select PHP Version** (hosts running CloudLinux):

1. Choose version **8.3**, 8.4, or 8.5 as the current version and choose
   **Set as current**. This applies to your whole account.
2. On the **Extensions** tab, make sure `gd`, `zip`, `pdo_mysql`, `mbstring`,
   `intl`, `bcmath`, and `fileinfo` are ticked.
3. On the **Options** tab, set the four values in the table above.

If your cPanel has both tools, **Select PHP Version** applies only to domains
that **MultiPHP Manager** leaves on **inherit**. If your host's instructions
say otherwise, follow them.

On plain cPanel servers (without **Select PHP Version**), you cannot add PHP
extensions yourself. The web installer checks them in step 9; if one is
missing, ask your host to install it.

Write down the PHP version you chose; the terminal commands later must use the
same one.

## 6. Open the Terminal and choose PHP

In **Terminal**, go to the KomaEngine folder:

```bash
cd ~/komaengine
ls artisan
```

`ls` must print `artisan`.

In cPanel's terminal, plain `php` usually runs the **server's default** PHP
version, not the one you chose for the domain. Use the full path of the
version you chose in step 5. Try these, replacing `83` with your version
(`84` for 8.4, `85` for 8.5):

```bash
/usr/local/bin/ea-php83 -v
/opt/alt/php83/usr/bin/php -v
```

Use the first one that prints `PHP 8.3` (or your version) followed by `(cli)`.
The second form is for hosts with **Select PHP Version**. Do not use
`/usr/bin/php` or `/usr/bin/ea-php83`: those are the web versions of PHP and
do not run commands correctly.

Write down the path that worked. To avoid typing it in every command, make
`php` mean that version for this terminal session, for example:

```bash
alias php=/usr/local/bin/ea-php83
php -v
```

Run the `alias` line again whenever you open a new terminal. If neither path
works, ask your host for the command-line path of PHP 8.3.

## 7. Create and fill in the settings file

Create `.env` from the template and make it private:

```bash
cp .env.example .env
chmod 600 .env
```

Open it in the terminal editor:

```bash
nano .env
```

Change these lines to your own values, and add the `SESSION_COOKIE` line at the
end:

```dotenv
APP_URL=https://manga.example.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=acct_komaengine
DB_USERNAME=acct_koma
DB_PASSWORD="the database password from step 2"

SESSION_COOKIE=komaengine_session
```

- `DB_CONNECTION`: `mysql` works for both MySQL and MariaDB.
- `DB_DATABASE`, `DB_USERNAME`: the **full** names, including the account
  prefix.
- `DB_PASSWORD`: keep the double quotes around it.
- `SESSION_COOKIE` keeps you signed in when the installer finishes (see
  [known issues](README.md#known-issues-in-101)).

Leave everything else, including the empty `APP_KEY=`, as it is. Save with
`Ctrl+O`, `Enter`, and exit with `Ctrl+X`.

You can also edit `.env` in File Manager (select it and choose **Edit**). If you
do, run `chmod 600 .env` in the terminal afterwards.

## 8. Generate the key and prepare storage

Run these four commands in the `komaengine` folder:

```bash
php artisan key:generate --force
ln -s ../storage/app/public public/storage
php artisan koma:media:protect
php artisan koma:doctor --mode=bootstrap
```

What they do:

1. `key:generate` creates the site's secret key in `.env`. Run it only this
   once; a new key on an existing site signs everyone out and makes encrypted
   data unreadable.
2. `ln -s` links `public/storage` to the folder where public uploads live.
3. `koma:media:protect` prepares the private storage for manga pages.
4. `koma:doctor` checks that everything is ready.

The check should print:

```text
app_key          OK
media_driver     OK
database         OK
migrations       FAIL
runtime_paths    OK
cache            OK
storage_link     OK
legacy_public_media_absent OK
```

`migrations FAIL` is expected here: the installer creates the database tables
in the next step. If any other line says `FAIL`:

- `database FAIL`: check the database values in `.env` (step 7), especially
  the full names with the account prefix. Keep `DB_HOST=localhost`; cPanel
  database users are normally set up to connect through `localhost`.
- `app_key FAIL`: run `php artisan key:generate --force` again.
- Anything else: see [Troubleshooting](troubleshooting.md).

## 9. Run the web installer

Print the setup token:

```bash
php artisan koma:installer-token
```

It prints a 64-character code. Treat it like a password: paste it straight
into the installer and do not put it in a URL, chat, ticket, or screenshot.

Open `https://manga.example.com/install` in your browser and go through the
screens:

| Screen | What to do |
|---|---|
| **Authorize this installer** | Paste the token and choose **Unlock setup**. |
| **Choose your language** | Pick the site language and choose **Continue**. |
| **Welcome to the five-minute install** | A list of checks. Every required row must show it is ready. If **Web document root** is not ready, recheck step 4; for any other row, see [Troubleshooting](troubleshooting.md). Choose **Continue**. |
| **Database connection** | The values from `.env` are already filled in. Type the database password, choose **Test connection**, wait for **Connection successful.**, then **Continue**. |
| **Where should pages live?** | Keep **Local disk** and choose **Continue**. |
| **Set up your site** | Enter the site title, the address (`https://manga.example.com`), and your administrator name, email, and password. Choose **Install Koma Engine**. |
| **Koma Engine is installed** | Choose **Open dashboard**. |

The installer creates the database tables, your administrator account, and
`robots.txt`, and then locks itself: the token stops working and `/install`
cannot be used again.

Back in the terminal, run the full check:

```bash
php artisan koma:doctor --mode=operational
```

Every line, including `migrations` and `installed`, should now be `OK`.

## 10. Set up the two cron jobs

KomaEngine needs two jobs that run every minute: the **scheduler**, which
publishes scheduled chapters, and the **queue worker**, which processes archive
imports.

First find the full path of the `komaengine` folder. In the terminal, in that
folder, run:

```bash
pwd
```

It prints something like `/home/acct/komaengine`. For PHP, use the full path
that worked in step 6. Cron jobs must always use that full path; plain `php`
in a cron job can run the wrong PHP.

Open **Cron Jobs** (in **Advanced**). Under **Add New Cron Job**, choose the
once-per-minute entry in **Common Settings**, or type `*` in each of the five
boxes (**Minute**, **Hour**, **Day**, **Month**, **Weekday**). Paste the
command and choose **Add New Cron Job**. Add two jobs.

**Job 1, the scheduler** (one line; replace the folder and PHP path):

```text
cd /home/acct/komaengine && /usr/local/bin/ea-php83 artisan schedule:run >/dev/null 2>&1
```

**Job 2, the queue worker** (one line):

```text
cd /home/acct/komaengine && /usr/local/bin/ea-php83 -d memory_limit=512M artisan queue:work database --queue=archive-imports,default --tries=1 --timeout=3600 --sleep=3 --stop-when-empty >/dev/null 2>&1
```

The `>/dev/null 2>&1` at the end stops cPanel from emailing you every minute.
To see a job's errors while testing, run its command (without that ending) in
the terminal.

The worker processes whatever is waiting and then exits, so nothing keeps
running between imports. If an import is still running when the next minute
starts, the new worker sees nothing to do and exits at once. The
`-d memory_limit=512M` part matters: the PHP settings from step 5 apply to the
website, not to cron jobs.

Some hosts limit cron jobs:

- **Minimum interval.** Some hosts allow cron jobs only every 5 or 15 minutes.
  KomaEngine still works, but scheduled chapters publish up to that many
  minutes late and archive imports wait that long to start.
- **Running time.** Some hosts stop long-running jobs. If large archive imports
  fail, ask your host whether a PHP job may run for up to an hour, or import
  smaller archives.

For the details, see [Queue and scheduler](shared-hosting.md#queue-and-scheduler).

## 11. Check that everything works

1. Sign in at `https://manga.example.com/login`.
2. Create a small test series, upload a chapter, publish it, and open it on
   the public site. The pages should load.
3. Schedule a test chapter a few minutes ahead and check that it publishes on
   its own. That proves the scheduler job works.

Delete the test content when you are done.

## If something goes wrong

### The installer says **Web document root** is not ready

The domain is not serving `komaengine/public`. In the terminal, run
`ls -l ~/public_html/` and check that the domain's folder is a link to
`/home/acct/komaengine/public` (step 4). In **Domains**, check that the
domain's document root is that folder.

### Every page except the home page is "404 Not Found"

KomaEngine's `public/.htaccess` routes every page through `index.php`. Check
that `public/.htaccess` exists (turn on hidden files in File Manager). If it
does, ask your host whether `.htaccess` overrides are allowed for your account.

### Uploads fail or stop partway

Check the four values from step 5 in **MultiPHP INI Editor** or
**Select PHP Version**. Some hosts also have an upload limit of their own,
separate from PHP's; ask them to allow uploads of 520 MB if large archive
uploads still fail.

### A cron job does nothing, or prints a list of commands

The job is using the wrong PHP. Use the full path from step 6, such as
`/usr/local/bin/ea-php83`, not plain `php` or `/usr/bin/php`. To see a job's
error, run its command in the terminal without the `>/dev/null 2>&1` ending.

### `php -v` shows an old version, or `php: command not found`

Use the full path from [step 6](#6-open-the-terminal-and-choose-php), or set the
`alias` again.

More messages and fixes: [Troubleshooting](troubleshooting.md).

## Next steps

- **Back up**: set up regular full-site backups with a copy kept outside this
  hosting account; see the [recovery guide](recovery.md).
- **Update**: never extract a new ZIP over the running site. Follow
  [Update and rollback](shared-hosting.md#update-and-rollback). After an
  update, check **MultiPHP Manager** and **MultiPHP INI Editor** again: cPanel
  can keep those choices in files inside `public/`, which a new release
  replaces.
