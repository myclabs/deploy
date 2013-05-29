# deploy

Deployment scripts

## Usage

```bash
deploy deploy [-v|--verbose] application version [path]
```

The deploy command will

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
