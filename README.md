# deploy

Deployment scripts

## Usage

```bash
deploy [--dry-run] [--update-db] [--restart-worker="..."] version [path]
```

```
Arguments:
 version               Which version to deploy (tag or branch name).
 path                  Path to deploy the application into. Default is the current directory. (default: "C:\\dev\\deploy")

Options:
 --dry-run             If set, do not run any command. This is appropriate for testing.
 --update-db           If set, 'build update' will be run and the DB will be updated. If not, the user will be asked.
 --restart-worker      If set, the given Gearman worker will be restarted. If not, the user will be asked.
 --help (-h)           Display this help message.
 --quiet (-q)          Do not output any message.
 --verbose (-v)        Increase verbosity of messages.
 --version (-V)        Display this application version.
 --ansi                Force ANSI output.
 --no-ansi             Disable ANSI output.
 --no-interaction (-n) Do not ask any interactive question.
```

## Examples

Deploys the 2.1.0 tag:

```bash
deploy 2.1.0
```

Deploys the master branch to another path:

```bash
deploy master /home/dev/inventory
```

Deploys non-interactively (updates the DB and restarts a worker):

```bash
deploy --update-db --restart-worker inventory-worker 2.1.0
```

## Installation

Checkout the repository and install the dependencies with composer:

```bash
git clone git@github.com:myclabs/deploy.git
cd deploy
composer install
```

The script needs to be run as root, so change the permissions of the file to avoid errors running it as non-root:

```bash
sudo chown root bin/deploy
sudo chmod 0744 bin/deploy
```

The script can now be executed with:

```bash
sudo bin/deploy --help
```

To install globally on the machine (and be able to use `deploy ...`), create a symlink:

```bash
sudo ln -s /usr/local/bin/deploy /path/to/project/bin/deploy
```
