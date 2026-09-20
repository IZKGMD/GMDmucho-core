/* Copyright (C) 2026 IZK */
(() => {
'use strict';

const KEY = 'mucho_admin_language';

const RU = {
'Main':'Главное',
'Dashboard':'Обзор',
'Analytics':'Аналитика',
'Control v4':'Управление v4',
'Monitoring':'Мониторинг',
'Content':'Контент',
'Players':'Игроки',
'Levels':'Уровни',
'Moderation':'Модерация',
'Comments':'Комментарии',
'Messages':'Сообщения',
'Social':'Социальное',
'Music':'Музыка',
'Tools':'Инструменты',
'Database':'База данных',
'API Tester':'Тестер API',
'Backups':'Резервные копии',
'Settings':'Настройки',
'System':'Система',
'Administrators':'Администраторы',
'Audit Log':'Журнал действий',
'Logout':'Выйти',

'Search':'Поиск',
'Quick search':'Быстрый поиск',
'Search everywhere':'Искать везде',
'Everywhere':'Везде',
'Search type':'Тип поиска',
'Open':'Открыть',
'Open player':'Открыть игрока',
'Open history':'Открыть историю',
'Save':'Сохранить',
'Save changes':'Сохранить изменения',
'Save settings':'Сохранить настройки',
'Edit':'Редактировать',
'Delete':'Удалить',
'Restore':'Восстановить',
'Refresh':'Обновить',
'Download':'Скачать',
'Create':'Создать',
'Add':'Добавить',
'Apply':'Применить',
'Apply to selected':'Применить к выбранным',
'Cancel':'Отмена',
'Confirm':'Подтвердить',

'Accounts':'Аккаунты',
'Account':'Аккаунт',
'Username':'Имя пользователя',
'Email':'Почта',
'Password':'Пароль',
'New password':'Новый пароль',
'Change password':'Сменить пароль',
'Role':'Роль',
'Status':'Статус',
'Active':'Активен',
'Inactive':'Неактивен',
'Banned':'Забанен',
'Ban':'Забанить',
'Unban':'Разбанить',
'Activate':'Активировать',
'Deactivate':'Деактивировать',
'Change role':'Изменить роль',
'Player History':'История игрока',
'Registration':'Регистрация',
'Registration date':'Дата регистрации',
'Last activity':'Последняя активность',
'Last IP':'Последний IP',

'Statistics':'Статистика',
'Stats':'Статы',
'Stars':'Звёзды',
'Moons':'Луны',
'Diamonds':'Алмазы',
'Demons':'Демоны',
'Secret Coins':'Секретные монеты',
'User Coins':'Пользовательские монеты',
'Creator Points':'Очки автора',
'Downloads':'Скачивания',
'Likes':'Лайки',

'Name':'Название',
'Author':'Автор',
'Difficulty':'Сложность',
'Requested Stars':'Запрошенные звёзды',
'Set Stars':'Поставить звёзды',
'Remove Featured':'Снять Featured',
'Remove Epic':'Снять Epic',
'Uploaded levels':'Загруженные уровни',
'Latest levels':'Последние уровни',

'Bulk Players':'Массово: игроки',
'Bulk Levels':'Массово: уровни',
'Bulk Actions':'Массовые действия',
'Moderation Queue':'Очередь модерации',
'Queue is empty':'Очередь пуста',

'Friends':'Друзья',
'Friends / Blocks':'Друзья / блоки',
'Blocks':'Блокировки',
'Friend Requests':'Запросы в друзья',
'Profile Comments':'Комментарии профиля',
'Sender':'Отправитель',
'Recipient':'Получатель',
'Subject':'Тема',
'Body':'Текст',
'Read':'Прочитано',
'Unread':'Не прочитано',

'GDPS Settings':'Настройки GDPS',
'GDPS Name':'Название GDPS',
'Server Name':'Название сервера',
'Server Status':'Состояние сервера',
'Registration Enabled':'Регистрация включена',
'Registration enabled':'Регистрация включена',
'Registration Disabled':'Регистрация выключена',
'Maintenance':'Техническое обслуживание',
'Maintenance Mode':'Режим обслуживания',
'Default Message Privacy':'Сообщения по умолчанию',
'Friends Only':'Только друзья',
'Everyone':'Все',
'Nobody':'Никто',
'Allowed':'Разрешены',
'Restricted':'Ограничены',
'Disabled':'Выключено',
'Maximum Level Data Size, Bytes':'Максимальный размер данных уровня, байт',

'Services':'Сервисы',
'API Health':'Состояние API',
'Anti-Abuse':'Антиабуз',
'Live Logs':'Логи в реальном времени',
'PHP-FPM':'PHP-FPM',
'Nginx Error':'Ошибки Nginx',
'Nginx Access':'Доступ Nginx',
'Cloudflare':'Cloudflare',
'No obvious anomalies detected.':'Явных аномалий не обнаружено.',
'No log output':'Логи пусты',

'Code Backup':'Резервная копия кода',
'Database Backup':'Резервная копия БД',
'Create Backup':'Создать резервную копию',
'Code backups':'Резервные копии кода',
'Database backups':'Резервные копии БД',
'File':'Файл',
'Size':'Размер',
'Date':'Дата',

'Administrator':'Администратор',
'Create Administrator':'Создать администратора',
'Add administrator':'Добавить администратора',
'Two-Factor Authentication':'Двухфакторная аутентификация',
'Enable 2FA':'Включить 2FA',
'Disable 2FA':'Отключить 2FA',
'2FA code, if enabled':'Код 2FA, если включён',
'6-digit code':'6-значный код',
'Sign in':'Войти',

'Error':'Ошибка',
'Warning':'Предупреждение',
'Success':'Успешно',
'Done':'Готово',
'No data':'Нет данных',
'Nothing found.':'Ничего не найдено.',
'Account not found.':'Аккаунт не найден.',
'Player not found.':'Игрок не найден.',
'Operation completed.':'Операция выполнена.',
'Settings saved.':'Настройки сохранены.',
'Settings applied.':'Настройки применены.',
'Account saved.':'Аккаунт сохранён.',
'Level saved.':'Уровень сохранён.',
'Password changed.':'Пароль изменён.',

'Accounts, bans and statistics':'Аккаунты, баны и статистика',
'Level rating and review':'Рейтинг и проверка уровней',
'Test GD endpoints':'Проверка GD endpoints',
'Code and database':'Код и база данных',
'Move to trash':'Переместить в корзину',
'Delete account permanently':'Удалить аккаунт навсегда',
'Disable new registrations':'Запретить новые регистрации',

'Filter':'Фильтр',
'Filter Players':'Фильтр игроков',
'Next':'Следующая',
'Previous':'Предыдущая',
'First':'Первая',
'Last':'Последняя',
'Yes':'Да',
'No':'Нет',
'On':'Вкл',
'Off':'Выкл'
};

function getLang() {
    return localStorage.getItem(KEY) === 'ru' ? 'ru' : 'en';
}

function setLang(lang) {
    lang = lang === 'ru' ? 'ru' : 'en';

    localStorage.setItem(KEY, lang);

    document.cookie =
        'mucho_admin_language=' + lang +
        '; path=/admin; max-age=31536000; SameSite=Lax';

    location.reload();
}

function translateTextNode(node) {
    const raw = node.nodeValue;
    const value = raw.trim();

    if (!value || !RU[value]) return;

    node.nodeValue = raw.replace(value, RU[value]);
}

function translateElement(el) {
    if (!(el instanceof Element)) return;

    if (['SCRIPT','STYLE','CODE','PRE'].includes(el.tagName))
        return;

    for (const attr of ['placeholder','title','aria-label']) {
        const val = el.getAttribute(attr);

        if (val && RU[val])
            el.setAttribute(attr, RU[val]);
    }

    for (const node of el.childNodes) {
        if (node.nodeType === Node.TEXT_NODE) {
            translateTextNode(node);
        } else if (node.nodeType === Node.ELEMENT_NODE) {
            translateElement(node);
        }
    }
}

function translatePage() {
    if (getLang() !== 'ru')
        return;

    translateElement(document.body);

    document.documentElement.lang = 'ru';
}

function bindFlags() {
    const buttons =
        document.querySelectorAll('#muchoLangSwitcher [data-lang]');

    buttons.forEach(btn => {
        const lang = btn.dataset.lang;

        btn.onclick = ev => {
            ev.preventDefault();
            ev.stopPropagation();
            setLang(lang);
        };

        btn.classList.toggle(
            'active',
            lang === getLang()
        );
    });
}

function init() {
    bindFlags();
    translatePage();

    if (getLang() === 'ru') {
        const observer = new MutationObserver(entries => {
            for (const entry of entries) {
                for (const node of entry.addedNodes) {
                    if (node.nodeType === Node.TEXT_NODE)
                        translateTextNode(node);
                    else if (node.nodeType === Node.ELEMENT_NODE)
                        translateElement(node);
                }
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    console.log(
        'MuchoControl language:',
        getLang()
    );
}

if (document.readyState === 'loading')
    document.addEventListener('DOMContentLoaded', init);
else
    init();

})();
