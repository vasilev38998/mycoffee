# Автоматический delta-deploy на Beget

Workflow `.github/workflows/deploy-beget.yml` выкладывает production после успешного `Project quality` на `main` и дополнительно ждёт успешный `Runtime regression` для того же commit SHA.

## Что именно загружается

Генератор `scripts/beget_delta_manifest.py` строит разницу между последним успешно отмеченным production commit и новым `main`, после чего `rsync` передаёт только изменённые/новые deployable-файлы. Удалённые из Git deployable-файлы удаляются на Beget отдельным безопасным шагом.

Разрешены:

- корневые `*.php` и `.htaccess`;
- `api/`, `assets/`, `cron/`, `customer/`, `database/`, `inc/`;
- защитные `.htaccess` внутри `customer/uploads/` и `storage/logs/`.

Никогда автоматически не трогаются:

- `config.php`, `.env*`, `config.example.php`;
- пользовательские файлы в `customer/uploads/` и `uploads/`;
- runtime-логи/кэш/сессии в `storage/`, `logs/`, `tmp/`;
- `.github/`, `tests/`, `scripts/`, `evotor-app/`, README и `.gitignore`.

Это специально сделано, чтобы deploy не мог стереть фотографии клиентов, логи, локальный конфиг или APK-проект.

## GitHub Secrets

В `Settings → Secrets and variables → Actions` добавьте:

- `BEGET_HOST` — SSH hostname Beget;
- `BEGET_USER` — SSH/SFTP пользователь;
- `BEGET_SSH_KEY` — приватный SSH-ключ deploy-пользователя;
- `BEGET_PATH` — абсолютный путь к корню сайта на Beget, например `/home/.../public_html`.

Опционально:

- `BEGET_PORT` — SSH port, по умолчанию `22`;
- `BEGET_KNOWN_HOSTS` — закреплённая строка host key. Если не указана, workflow получает ключ через `ssh-keyscan` при запуске.

Секреты нельзя добавлять в код или commit.

## SSH-ключ

Рекомендуется отдельный ключ только для deploy:

```bash
ssh-keygen -t ed25519 -C "github-beget-deploy" -f beget_deploy
```

Публичную часть `beget_deploy.pub` добавьте для SSH-пользователя Beget. В `BEGET_SSH_KEY` сохраните содержимое приватного файла `beget_deploy` целиком, включая строки `BEGIN/END ... PRIVATE KEY`.

Если Beget позволяет ограничить пользователя каталогом проекта, используйте такой отдельный аккаунт вместо основного SSH-доступа.

## Как узнать `BEGET_PATH`

Подключитесь по SSH, перейдите в каталог, где лежит корневой `.htaccess` и PHP-файлы Kapouch, затем выполните:

```bash
pwd
```

Результат целиком сохраните в `BEGET_PATH`. Workflow специально принимает только абсолютный безопасный путь и отказывается работать с `/`.

## Первый запуск

Workflow хранит на сервере служебный файл `.kapouch-deployed-sha`. После каждого успешного deploy/health-check туда записывается commit SHA production.

Если маркера ещё нет, workflow берёт родительский commit нового `main` как базу. Поэтому перед первым включением автоматики желательно, чтобы Beget уже соответствовал текущему `main` до следующего merge.

Если production отстаёт на известный commit, откройте `Actions → Deploy Beget delta → Run workflow` и укажите этот commit в `base_sha`. Тогда будут отправлены все deployable-изменения от него до выбранного `target_sha`.

## Защита процесса

- workflow не делает ничего, пока обязательные Secrets не настроены;
- автоматический запуск принимается только от `push`-проверки `Project quality` на `main` внутри этого же репозитория;
- `Project quality` должен пройти успешно;
- для автоматического запуска workflow ждёт `Runtime regression` того же commit;
- используется SSH `BatchMode` + strict host key checking;
- `rsync --delay-updates` сначала передаёт временные версии файлов и только затем переключает обновления;
- удаление разрешено только для путей, прошедших тот же deploy allowlist;
- после выкладки проверяются `https://kapouch.store/` и `https://app.kapouch.store/`;
- если health-check падает, production SHA marker не продвигается, поэтому следующий запуск снова увидит недоставленные изменения.

## Обычный цикл после настройки

`branch → PR → CI → squash merge в main → post-merge CI → delta deploy на Beget → health-check`.

Ручная замена изменённых файлов после этого обычно не нужна.
