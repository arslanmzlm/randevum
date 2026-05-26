# `.ai/` folder — committing to git

`.ai/` is tracked in git like the rest of the repo. The rule is just about *when* to commit it:

- Stage or commit files under `.ai/` only when the user explicitly tells you to — never on your own initiative.
- Never use `git add -A` or `git add .`; stage files by listing them explicitly.
- Don't add `.ai/` to `.gitignore`.
