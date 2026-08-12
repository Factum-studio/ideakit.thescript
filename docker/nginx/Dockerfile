# syntax=docker/dockerfile:1.7

FROM nginx:1.28.3-alpine3.23@sha256:a8b39bd9cf0f83869a2162827a0caf6137ddf759d50a171451b335cecc87d236

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY web /app/web

STOPSIGNAL SIGQUIT
