FROM nginx:1.30.6-alpine3.24

# Refresh security patches not yet included in the upstream image rebuild.
RUN apk upgrade --no-cache
