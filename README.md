# deploy

Deployment scripts

## Installation

Checkout the repository:

```bash
git clone git@github.com:myclabs/deploy.git
cd deploy
composer install
chmod +x bin/deploy
```

The script can now be executed with:

```bash
bin/deploy help
```

To install globally on the machine (and be able to use `deploy ...`), create a symlink:

```bash
sudo ln -s /usr/local/bin/deploy /path/to/project/bin/deploy
```

## Usage

```bash
deploy [-v|--verbose] application version [path]
```
