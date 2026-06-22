#!/bin/sh
set -e

# Symfony exige un fichier .env ; les vraies valeurs viennent des variables Render.
if [ ! -f .env ]; then
  cp .env.example .env
fi

php bin/console doctrine:migrations:migrate --no-interaction --env=prod

if [ "${SEED_ON_DEPLOY:-false}" = "true" ]; then
  php bin/console app:seed-dev --env=prod --no-interaction
fi

php bin/console cache:warmup --env=prod

exec php -S "0.0.0.0:${PORT:-8000}" -t public
