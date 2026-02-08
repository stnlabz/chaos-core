Welcome to the Chaos CMS Core

Core is a Hybrid version of the Chaos CMS meaning things like database configuration and such are maintained in JSON format, modules can utilize their own internal data as well like with the Lite Version, but utilization of a database is available and HIGHLY receommended.

Without a database connection, this platform does work but without users, modules, posts, etc, so it is HIGHLY recommended that you setup a database to use.

1. Setup a database with a username and password on whatever Hosting provider that you use
2. The index will automatically redirect to /install if it finds it in in its root path
3. Fill in the required data on the form

Some key things will happen here.
1. Your database connection data will be stored in /app/config/config.json
2. The install directory will be deleted
3. You should be redirected to /login

Once at login, login to the site using the username/password you setup during the install
Then after you have been logged in, it it recommended that you go to /admin, then settings, and setup the rest of your site.

If you happen to stumble upon any issues, please go to https://github.com/stnlabz/chaos-core and submit an issue.

It is recommended although not always an option, to do a fresh install
Previous installs are recommended to get the latest install script
