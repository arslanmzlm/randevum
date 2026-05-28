# Chromium + system deps for Pest browser tests (pest-plugin-browser → Playwright).
# Pin the same playwright version as package.json so the installed browser revision matches.
# Browsers install to a system path (PLAYWRIGHT_BROWSERS_PATH, also set in config.yaml's
# web_environment) so the non-root web user can find them. Rebuild with `ddev restart`.
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright
RUN npx -y playwright@1.60.0 install --with-deps chromium \
    && chmod -R a+rX /ms-playwright
