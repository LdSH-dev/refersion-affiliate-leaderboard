# Refersion Affiliate Leaderboard

## Requirements

Docker. Everything runs in containers, so nothing else is needed on your machine.

## Run

```bash
make setup   # first time only: build the image, install dependencies, create .env
make up      # start the app
```

Open http://localhost:8000.

By default the app uses a local mock of the Refersion API, so it works without credentials.

To print the leaderboard in the terminal instead:

```bash
make board
```

## Use a real Refersion account

Put your credentials in `.env`:

```dotenv
REFERSION_BASE_URL=https://api.refersion.com/v2026-08
REFERSION_TOKEN_URL=https://auth.refersion.com/oauth/token
REFERSION_CLIENT_ID=<client id>
REFERSION_CLIENT_SECRET=<client secret>
```

Then run `make board ARGS=--fresh`, or reload the page and click **Refresh**.

## Tests

```bash
make test      # backend
make test-js   # frontend
```

## Stop

```bash
make down
```
