FROM php:8.4-cli-alpine
WORKDIR /app
RUN addgroup -S codivanta && adduser -S codivanta -G codivanta
COPY . .
RUN mkdir -p data && chown -R codivanta:codivanta data && chmod 700 data
USER codivanta
EXPOSE 8080
ENV CODIVANTA_DEMO_MODE=1
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/health") ? 0 : 1);'
CMD ["sh","-c","php bin/seed.php && exec php -S 0.0.0.0:8080 -t public public/router.php"]
