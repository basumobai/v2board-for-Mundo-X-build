FROM nginxinc/nginx-unprivileged:1.30.6-alpine

USER root
RUN apk upgrade --no-cache
USER 101:101
