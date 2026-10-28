# Install on Plesk

This guide installs the KomaEngine release ZIP on a website hosted with
**Plesk Obsidian**. You do everything in the Plesk web interface, plus a few
commands in a terminal. No Composer, Node, or Git is needed.

Plan on about 30 minutes. The examples use the domain `manga.example.com`;
replace it with yours everywhere.

> **Do not unpack the ZIP into `httpdocs`.** That would make the whole
> application, including the file with your database password and secret key,
> downloadable from the web. This guide puts KomaEngine **next to** `httpdocs`
> and points the domain at its `public` folder instead.

## Before you start

You need:

- **A domain or subdomain in Plesk** for the site. It can be a new subdomain,
  such as `manga.example.com`, so your existing site is untouched. To create
  one, go to **Websites & Domains** and choose **Add Subdomain** (or
  **Add Domain**).
- **SSH access with `/bin/bash`** for the subscription. You use it through
  Plesk's built-in **SSH Terminal** or any SSH program. Customer accounts can
  often choose only **/bin/bash (chrooted)**, which has no PHP inside it. In
  that case ask your host: "Please enable SSH access with /bin/bash (not
  chrooted) for my subscription." See [step 6](#6-open-a-terminal).
- **The release ZIP and its SHA-256** from the
  [download page](https://komaengine.com/download/).

If your hosting company manages Plesk for you and you do not see an option
mentioned here, ask them to enable it. Each step says what to ask for.

### What the files will look like

Every Plesk subscription has a home folder, shown as the top level in the
File Manager (on the server it is `/var/www/vhosts/<your main domain>`).
Subdomains and extra domains in the same subscription share it. After this
guide it will contain:

```text
/                          the subscription's home folder
├── httpdocs/              the main domain's web folder (left as it is)
└── komaengine/            KomaEngine: private
    ├── .env               settings, database password, secret key
    ├── storage/           manga, uploads, logs
    └── public/            the folder the domain will serve
```

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

1. In Plesk, go to **Websites & Domains** and open your domain.
2. Choose **Databases**, then **Add Database**.
3. Fill in:
   - **Database name**: for example `komaengine`.
   - **Database server**: the MariaDB or MySQL server offered (usually there is
     only one).
   - **Related site**: your domain.
   - **Create a database user**: on. Enter a **Database user name** (for example
     `komaengine`) and a strong **Password**. Choose **Generate** to have Plesk
     make one.
   - **User has access to all databases within the subscription**: off.
4. Choose **OK**.

Write down the database name, user name, and password exactly as Plesk shows
them; some hosts add a prefix to the names. On the **Databases** page,
**Connection Info** shows the host name to use, which is usually `localhost`.
You need these in step 7 and again in the installer.

## 3. Choose PHP 8.3 or newer

1. Open your domain in **Websites & Domains** and choose **PHP**
   (**PHP Settings**).
2. Make sure **PHP support** is on, and set **PHP version** to **8.3**,
   **8.4**, or **8.5**.
3. Set the handler to **FPM application served by Apache**. This matters:
   with a handler "served by nginx", Plesk ignores KomaEngine's routing file
   (`public/.htaccess`) and most pages show "404 Not Found".
4. Further down, set these values. Choose **Custom** where a list does not
   offer the number:

   | Setting | Value |
   |---|---|
   | `memory_limit` | `512M` |
   | `max_execution_time` | `3600` |
   | `upload_max_filesize` | `512M` |
   | `post_max_size` | `520M` |

5. Choose **OK** (or **Apply**).

Write down the PHP version you chose; the terminal commands later must use the
same one.

KomaEngine also needs the GD, ZIP, PDO MySQL, mbstring, intl, bcmath, and
fileinfo extensions. Plesk's PHP builds normally include them, and the web
installer checks them for you in step 9. To look now, choose
**View the phpinfo() page** on the same PHP page. If one is missing, ask your
host. If you administer the server yourself, go to **Tools & Settings** →
**PHP Settings**, choose the PHP version, and enable it there.

## 4. Upload and extract the ZIP

1. Open your domain and choose **Files** (the File Manager). Go to the top
   level, the subscription's home folder: the one that contains `httpdocs`.
   Stay here; do not open `httpdocs`.
2. Choose **Upload** (the **+** button, then **Upload File**) and select the
   ZIP.
3. When the upload finishes, click the ZIP's row and choose
   **Extract Files**, then **OK**. It extracts into the current folder.
4. A new `komaengine` folder appears next to `httpdocs`. Open it and check that
   it contains `artisan`, `public`, `storage`, and `.env.example`.
5. Go back to the home folder and delete the ZIP; it is no longer needed.

If the File Manager refuses the upload because the file is too large, upload
it with an SFTP program such as FileZilla or Cyberduck instead, using your
subscription's SSH or FTP login, then extract it in the File Manager.

## 5. Point the domain at `komaengine/public` and turn on HTTPS

1. Open your domain and choose the **Hosting & DNS** tab, then **Hosting**.
   (Older Plesk versions call this **Hosting Settings**.)
2. Change **Document root** from its current value (`httpdocs`, or a folder
   named after the domain) to:

   ```text
   komaengine/public
   ```

   The path is relative to the subscription's home folder, where you
   extracted the ZIP.
3. Choose **Save** (or **OK**).

Raise the upload size limit. Plesk's web server accepts requests of up to
128 MB by default, which is less than KomaEngine's 500 MB archive limit:

1. In **Hosting & DNS**, choose **Apache & nginx Settings**.
2. Set **Maximum allowed HTTP request body size** to at least 520 MB, and
   choose **OK**.

If that setting is missing, your Plesk is older or your host has hidden it;
ask your host to allow request bodies of 520 MB for the domain. Uploads up to
128 MB work without it.

Now get a free certificate:

1. Open your domain and choose **SSL/TLS Certificates**.
2. Choose **Install** for the free Let's Encrypt certificate. Tick the `www`
   option only if you use `www`. Enter an email address for expiry notices if
   asked, and choose **Get it free**. Plesk renews it automatically.
3. On the same page, turn on **Redirect from http to https**. (In
   **Hosting & DNS** → **Hosting** it is called
   **Permanent SEO-safe 301 redirect from HTTP to HTTPS**.)

Opening `https://manga.example.com/` at this point shows an error page; that
is expected until step 8.

## 6. Open a terminal

You need a terminal for steps 7 to 10.

First turn on shell access: open your domain, choose **Hosting & DNS** →
**Hosting**, set **SSH access** to **/bin/bash**, and choose **Save**. (Older
Plesk versions have this under **Web Hosting Access** as
**Access to the server over SSH**.) If the list offers only **Forbidden** and
**/bin/bash (chrooted)**, ask your host to enable `/bin/bash` (not chrooted)
for your subscription: the chrooted shell has no PHP, so the commands below
cannot run in it.

Then open a terminal in either of these ways:

- **SSH Terminal in Plesk**: open your domain's **Dashboard** and choose
  **SSH Terminal**. It signs you in as the subscription's user.
- **SSH from your computer**: `ssh your-user@manga.example.com`, using the
  subscription's system user name and password from the same **Hosting**
  page.

You start in the subscription's home folder. Go to the KomaEngine folder:

```bash
cd ~/komaengine
ls artisan
```

`ls` must print `artisan`. If it does not, run `pwd` and `ls` to see where you
are; the `komaengine` folder is next to `httpdocs`.

### Use the PHP version you chose

Plesk keeps each PHP version in its own folder, and plain `php` in the terminal
is often a different, older version. Check the one you chose in step 3,
replacing `8.3` if you chose another:

```bash
/opt/plesk/php/8.3/bin/php -v
```

It must print `PHP 8.3` (or your version). To avoid typing the path in every
command, make `php` mean that version for this terminal session:

```bash
alias php=/opt/plesk/php/8.3/bin/php
php -v
```

Run the `alias` line again whenever you open a new terminal.

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

Change these lines to your own values:

```dotenv
APP_URL=https://manga.example.com

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=komaengine
DB_USERNAME=komaengine
DB_PASSWORD="the database password from step 2"
```

- `DB_CONNECTION`: `mariadb` if Plesk showed a MariaDB server in step 2,
  `mysql` if it showed MySQL.
- `DB_DATABASE`, `DB_USERNAME`: the full names Plesk showed, including any
  prefix.
- `DB_PASSWORD`: keep the double quotes around it.

Leave everything else, including the empty `APP_KEY=`, as it is. Save with
`Ctrl+O`, `Enter`, and exit with `Ctrl+X`.

You can also edit `.env` in the Plesk File Manager (click the file name). If you
do, run `chmod 600 .env` in the terminal afterwards.

## 8. Prepare the site

Run one command in the `komaengine` folder:

```bash
php artisan koma:setup
```

It creates the site's secret key in `.env` (only if there is none yet), links
`public/storage` to the folder where public uploads live, prepares the private
storage for manga pages, and runs the readiness checks. It is safe to run
again at any time: it never replaces an existing key and only fixes what is
missing. It should print something like:

```text
Preparing KomaEngine in /var/www/vhosts/example.com/komaengine

  .env             OK         mode 0600
  APP_KEY          GENERATED  written to .env
  public/storage   CREATED    public/storage -> ../storage/app/public
  media storage    OK         protected

Readiness (koma:doctor --mode=bootstrap):
  app_key                    OK
  media_driver               OK
  database                   OK
  migrations                 PENDING
  runtime_paths              OK
  cache                      OK
  storage_link               OK
  legacy_public_media_absent OK

Next:
  1. Run php artisan koma:installer-token to print the setup token.
  2. Open https://manga.example.com/install over HTTPS and enter the token.
```

`migrations PENDING` is expected here: the installer creates the database
tables in the next step. If a step says `FAILED` or a check says `FAIL`, the
line tells you what to do; fix it and run `php artisan koma:setup` again.

- `database FAIL`: check the database values in `.env` (step 7).
- `public/storage FAILED` because the host does not let PHP create links: run
  the `ln -s` command the message shows, in the `komaengine` folder.
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
| **Welcome to the five-minute install** | A list of checks. Every required row must show it is ready. If **Web document root** is not ready, recheck step 5; for any other row, see [Troubleshooting](troubleshooting.md). Choose **Continue**. |
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

## 10. Set up the scheduled task

KomaEngine needs one task that runs every minute. It publishes scheduled
chapters and starts the queue worker that processes archive imports.

First find the full path of the `komaengine` folder. In the terminal, in that
folder, run:

```bash
pwd
```

It prints something like `/var/www/vhosts/example.com/komaengine`. Use that
path below.

In Plesk, open your domain and choose **Scheduled Tasks**, then **Add Task**:

- **Task type**: **Run a command**.
- **Command** (one line; replace the path and PHP version):

  ```text
  cd /var/www/vhosts/example.com/komaengine && /opt/plesk/php/8.3/bin/php artisan schedule:run >/dev/null 2>&1
  ```

- **Run**: **Cron style**, with the value `* * * * *` (every minute).
- **Notify**: **Do not notify** (or **Errors only**, if you want an email when
  it fails). Never **Every time**: the task runs every minute, so that would
  send an email every minute.

Choose **OK**. To test the task straight away, select it and choose
**Run Now**; it should finish without an error.

Plesk runs the task with your subscription's shell. If **Run Now** reports
that `/opt/plesk/php/8.3/bin/php` does not exist, the subscription is still on
the chrooted shell; set **SSH access** to **/bin/bash** as in step 6.

The task can start the queue worker because the settings file from step 7
contains `KOMA_QUEUE_VIA_SCHEDULER=true`. The worker processes whatever is
waiting and then exits, so nothing keeps running between imports, and a new
one does not start while an import is still running. For the details, and for
sites set up before 1.1.0 with two tasks, see
[Queue and scheduler](shared-hosting.md#queue-and-scheduler).

## 11. Check that everything works

1. Sign in at `https://manga.example.com/login`.
2. Create a small test series, upload a chapter, publish it, and open it on
   the public site. The pages should load.
3. Schedule a test chapter a few minutes ahead and check that it publishes on
   its own. That proves the scheduled task works.
4. In the admin menu, open **Health**. Every item should say **OK**; the
   scheduler shows a run from the last minute or two once the scheduled task is
   working.

Delete the test content when you are done.

## If something goes wrong

### Uploads fail with "413 Request Entity Too Large"

Plesk puts Nginx in front of the site, and Nginx has its own upload limit,
128 MB by default. Raise **Maximum allowed HTTP request body size** as in
[step 5](#5-point-the-domain-at-komaenginepublic-and-turn-on-https). On older
Plesk versions without that setting, the Plesk administrator can add
`client_max_body_size 520m;` under **Additional nginx directives** on the same
page.

### Every page except the home page is "404 Not Found"

KomaEngine's `public/.htaccess` routes every page through `index.php`. Plesk
reads it only when Apache runs PHP. Open the domain's **PHP** page and choose
the handler **FPM application served by Apache** (step 3).

If your host runs the domain on Nginx alone (**Apache & nginx Settings** has
**Proxy mode** turned off, and only "served by nginx" handlers are offered),
the Plesk administrator must add this under **Additional nginx directives**:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### `php: command not found`, or `php -v` shows an old version

Use the full path from [step 6](#use-the-php-version-you-chose), for example
`/opt/plesk/php/8.3/bin/php artisan ...`, or set the `alias` again.

More messages and fixes: [Troubleshooting](troubleshooting.md).

### Plesk's Laravel Toolkit

You do not need it. KomaEngine's own installer and the scheduled task
replace it. Do not use **Create Website** → **Laravel** for KomaEngine: that
installs a new, empty Laravel application instead.

## Next steps

- **Back up**: set up regular full-site backups with a copy kept outside this
  hosting account; see the [recovery guide](recovery.md).
- **Update**: never extract a new ZIP over the running site. Follow
  [Update and rollback](shared-hosting.md#update-and-rollback).
