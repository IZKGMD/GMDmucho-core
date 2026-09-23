# MuchoCore: compatibility matrix

Эта таблица описывает **реализованное поведение**, а не обещание, что каждый бинарник Geometry Dash уже был запущен против сервера.

| Область | MuchoCore | Что сверялось с Cvolton |
| --- | --- | --- |
| Account login/register | Да | legacy endpoint names |
| Level search/download/upload | Да | type/filter families and hashes |
| Daily/weekly/event level IDs | Да | negative timely IDs |
| Level lists | Да | list payload and paging |
| Comments | Да | version-aware response shape |
| Messages | Да | version aliases |
| Friends/blocks | Да | legacy endpoint aliases |
| Likes | Да | reversible vote semantics |
| Level scores | Да | offsets, progress decode, leaderboard format |
| Platformer scores | Да | time/points modes |
| Top artists | Да | 20-row paging and SoundCloud links |
| Comment history | Да | old/new client response layout |
| API v2 | Да | Mucho-specific admin/diagnostic API |

## Важное отличие

Cvolton использует много отдельных endpoint-файлов. MuchoCore намеренно сводит их к общему router/controller/service/repository слою.

Это не попытка заменить каждый файл один-в-один: цель — сохранить поведение клиента и одновременно уменьшить количество мест, где может появиться баг.

## Реальный клиент

Автоматические тесты проверяют:

- PHP syntax;
- routing aliases;
- protocol encoders;
- security guards;
- clean-install assumptions.

Они не заменяют тест реального Geometry Dash клиента. Перед публичным релизом конкретную версию клиента всё равно нужно запускать против развернутого сервера.
