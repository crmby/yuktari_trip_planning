# Стиль приложений Битрикс24 (Air): справочник для ИИ-агента

Файл для Claude Code, Codex и любого другого агента: по нему агент делает интерфейс приложения в стиле Битрикс24 (дизайн-система Air). Его можно читать и самому, не будучи программистом: правила сформулированы так, чтобы их можно было проверить глазами или поиском по коду.

К справочнику прилагается готовый файл **`b24-tokens.css`**: токены и базовые стили компонентов для пути Б. В скилле `bitrix24-apps` он лежит в `assets/b24-tokens.css`, на странице курса — отдельной загрузкой. Парный справочник про данные, ключи, встраивание и деплой — `bitrix24-apps.md`.

**Подготовлено:** KiselevLab, курс «Вайбкодинг в Битрикс24». **Версия:** 18.11.2026. Сверено с библиотекой `@bitrix24/b24ui-nuxt` **2.15.0** (репозиторий `bitrix24/b24ui`, коммит `9d98b4a` от 16.10.2026) и с её документацией.

**Пометки источника** стоят у правил и чисел:

| Пометка | Откуда |
|---|---|
| **[Промт]** | Базовый промт стиля (полный текст в разделе 12). Главный источник для пути Б. |
| **[b24ui]** | Исходники или документация библиотеки b24ui. В скобках указан файл. |
| **[Вывод]** | Наш вывод или рекомендация. Прямо в источниках этого нет, при сомнении проверяйте. |

---

## 0. Как пользоваться файлом

Есть два пути, и **смешивать их нельзя**: в одном проекте берите что-то одно.

**Путь А. Проект на Vue или Nuxt.** Ставите официальную библиотеку Битрикс24 `@bitrix24/b24ui-nuxt` (с иконками `@bitrix24/b24icons-vue`) и собираете экран из её компонентов: `B24Button`, `B24Card`, `B24Table` и т. д. Внешний вид задаёт тема библиотеки. Цвета вручную не задаёте, используете её цветовые имена (`air-primary`) и семантические классы (`text-description`, `bg-elevated`). Подробно в разделе 10.

**Путь Б. Обычный HTML/CSS/JS или PHP-шаблон.** Так устроены учебные шаблоны: `www/template.html` + `www/css/*.css`. Подключаете `b24-tokens.css`, размечаете страницу классами `.topbar`, `.card`, `.btn` и т. д., а цвета берёте только из CSS-переменных. Подробно в разделах 2–9.

```
Проект уже на Vue/Nuxt или вы готовы собрать его на Vue?
├─ да → ПУТЬ А: @bitrix24/b24ui-nuxt + @bitrix24/b24icons-vue (раздел 10).
│        Токены пути Б в проект НЕ переносить: тема библиотеки — источник истины.
└─ нет (HTML, PHP, jQuery, «просто страница») → ПУТЬ Б:
         1. скопировать b24-tokens.css в css/ и подключить ПЕРВЫМ (относительным путём);
         2. свои стили — после него, только через var(--…);
         3. разметка — классами из раздела 5;
         4. если страница живёт внутри портала Битрикс24 — раздел 7 (iframe).
```

**Если в проекте уже есть свой набор токенов** (например, тёмная тема в шаблоне «Битрикс24: расширенное приложение»), переход на этот стиль означает **замену темы целиком**, а не смешивание двух палитр. Такое решение принимает автор проекта.

**Синий в обоих путях один и тот же — официальный `#0075ff`** (токен Air `accent-main-primary`). Остальные значения в путях разные, и это нормально. Путь Б повторяет размеры и нейтральные цвета из промта (кнопка 13px с радиусом 6px). Библиотека b24ui рисует по своим токенам Air (кнопка 34px с радиусом 10px). Сравнение в разделе 2.3. Не подгоняйте одно под другое.

---

## 1. Принципы визуального языка

1. **Спокойно и плотно.** Светлый фон страницы, белые карточки с тонкой рамкой, компактный текст 13–14px. **[Промт]**
2. **Один акцент — синий.** Синим бывают основная кнопка, ссылки, фокус, заливка прогресса и выбранный пункт. На экране одна, максимум две синие кнопки. **[Промт]** Библиотека формулирует так же: «One solid primary button per view». **[b24ui]** (`skills/b24-ui-nuxt/references/guidelines/design-system.md`)
3. **Без теней и градиентов.** Исключений два: модалка и выпадающие списки, у них тень есть. **[Промт]** В b24ui тени тоже есть только у всплывающих слоёв: у карточки `B24Card` тени нет, у модалки и меню есть. Градиенты в b24ui применяются только в маркетинговых промо-блоках (`air-boost`). **[b24ui]** (`src/theme/card.ts`, `modal.ts`, `dropdown-menu.ts`, `recipes/overlays.md`)
4. **Выбранное состояние выглядит всегда одинаково:** фон `--brand-soft`, текст `--brand-ink`, `font-weight:600`. **[Промт]**
5. **Статусы передаются цветом и словом:** зелёный «готово», жёлто-коричневый «ожидание», красный «ошибка». Фон статуса — прозрачная подмешка его цвета. **[Промт]**
6. **Цифры набираются моноширинным шрифтом с табличными цифрами**, чтобы столбцы не «прыгали». **[Промт]**
7. **Только системный шрифт**, веб-шрифты не подключаем. **[Промт]** Библиотека делает то же: `--ui-font-family-primary: var(--ui-font-family-system)`. **[b24ui]** (`src/runtime/air-design-tokens/007_b24_global.css`)
8. **Иконки — линейные инлайн-SVG**, без эмодзи и иконочных шрифтов. **[Промт]** В пути А используется пакет `@bitrix24/b24icons-vue`. **[b24ui]**
9. **Только светлая тема** в наших приложениях (подробнее в разделе 8). **[Промт]**
10. **Движение короткое:** 0,12–0,18 с, и при `prefers-reduced-motion` анимации выключаются. **[Промт]**

---

## 2. Токены

### 2.1. Набор для пути Б (вставлять как есть) **[Промт]**, синие — **[b24ui]**

Четыре синих токена взяты из Air (светлая тема b24ui), остальное — из промта. В исходном промте синий был приглушённее (`#2c6ce0`); как вернуть его, если нужно, — в конце раздела 2.3.

```css
:root {
  --brand:#0075ff; --brand-hover:#0072f8; --brand-ink:#0154c8; --brand-soft:#e6f4ff;
  --page:#f1f4f6; --surface:#fff; --surface-2:#fbfbfb;
  --line:#00000014; --line-strong:#0000002e;
  --ink:#1a1c1f; --ink-2:#525c69; --ink-3:#828b95;
  --ok:#22ac43; --wait:#af8b35; --stop:#d1483f;
  --folder:#c98a2e;
  --r:6px;
  --ui:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
*{box-sizing:border-box}
body{margin:0;background:var(--page);color:var(--ink);font:400 14px/1.5 var(--ui);-webkit-font-smoothing:antialiased}
a{color:var(--brand);text-decoration:none} a:hover{text-decoration:underline}
```

| Токен | Значение | Для чего |
|---|---|---|
| `--brand` | `#0075ff` | Единственный акцент: основная кнопка, ссылки, обводка фокуса, заливка прогресса, подчёркивание активной вкладки. Air `accent-main-primary` |
| `--brand-hover` | `#0072f8` | Наведение на основную кнопку. В Air это `--brand` с подмесом 3% чёрного; разница с `--brand` едва заметна, так и задумано |
| `--brand-ink` | `#0154c8` | Текст выбранного или активного элемента. Air `accent-main-link` |
| `--brand-soft` | `#e6f4ff` | Фон выбранного или активного элемента, фон аватара. Air `accent-soft-blue-2` (фон tinted) |
| `--page` | `#f1f4f6` | Фон страницы |
| `--surface` | `#fff` | Карточки, шапка, левое меню, модалка, меню |
| `--surface-2` | `#fbfbfb` | Фон полей, наведения, шапки таблицы, чипов |
| `--line` | `#00000014` | Тонкие разделители: между строками, под шапкой, справа от меню |
| `--line-strong` | `#0000002e` | Рамки карточек, полей, таблиц, второстепенных кнопок |
| `--ink` | `#1a1c1f` | Основной текст |
| `--ink-2` | `#525c69` | Вторичный текст |
| `--ink-3` | `#828b95` | Подписи, даты, счётчики, кикеры, плейсхолдеры |
| `--ok` / `--wait` / `--stop` | `#22ac43` / `#af8b35` / `#d1483f` | Статусы «готово», «ожидание» (жёлто-коричневый), «ошибка» |
| `--folder` | `#c98a2e` | Иконка папки |
| `--r` | `6px` | Радиус кнопок, полей, пунктов меню |
| `--ui` / `--mono` | системные стеки | Текст интерфейса / цифры, даты, ID |

**Правила работы с токенами [Промт]:**

- Цвета, радиусы и шрифты задаются только через переменные. **Новых цветовых литералов не вводить.** В самом промте есть ровно три исключения, все они относятся к теням и подложке: `#00000059`, `#00000040`, `#00000022`.
- Уровни текста: `--ink` для основного текста, `--ink-2` для вторичного, `--ink-3` для подписей, дат и счётчиков.
- Фон статуса: `color-mix(in srgb, var(--ok) 10–14%, transparent)`, рамка — та же подмешка на 30–40%.
- Выбранное или активное состояние всегда одно: фон `--brand-soft`, текст `--brand-ink`, `font-weight:600`.
- Радиус-токен в наборе один, `--r:6px`. Остальные радиусы промт задаёт числами: 8–10px у карточки, 10px у таблицы и модалки, 5px у сегмента крошек, 100px у пилюль. В `b24-tokens.css` они так и записаны.

### 2.2. Семантические классы b24ui (путь А) **[b24ui]**

Источник: `src/runtime/index.css` (`@theme default inline`). Значения указаны для светлого контекста (`003_b24_context_light.css`). Классы с сырыми цветами Tailwind (`text-gray-500`, `bg-blue-600`) в пути А **запрещены** (`SKILL.md`, правило 2).

| Класс | CSS-переменная b24ui | Значение (light) | Назначение по документации |
|---|---|---|---|
| `text-dimmed` | `--ui-color-base-6` | `#dfe0e3` | плейсхолдеры, отключённое |
| `text-muted` | `--ui-color-design-plain-na-content-secondary` → base-5 | `#c9ccd0` | подсказки, второстепенные метаданные |
| `text-description` | `--b24ui-typography-description-color` → base-3 | `#828b95` | обычный читаемый текст, описания |
| `text-legend` | `--b24ui-typography-legend-color` → base-3 | `#828b95` | подзаголовки, легенды, заголовки групп |
| `text-label` | `--b24ui-typography-label-color` → base-2 | `#525c69` | подписи полей, заголовки секций |
| `text-primary` | `--ui-color-accent-main-primary` | `#0075ff` | акцентный текст |
| `bg-default` | `--ui-color-bg-content-primary` | `#fff` | в скилле назван «фоном страницы», но по значению это белый |
| `bg-muted` | `--ui-color-design-tinted-na-bg` → base-7 | `#edeef0` | наведение, чередование строк |
| `bg-elevated` | `--ui-color-bg-content-secondary` | `#fbfbfb` | приподнятые поверхности |
| `bg-accented` | `--ui-color-accent-soft-element-blue` | `#0154c8` | активное или выбранное; **это насыщенный синий**, текст на нём только белый |
| `border-default` / `divide-default` / `ring-default` | `--ui-color-divider-default` | `#f0f0f0` | обычные границы |
| `border-muted` | `--ui-color-design-tinted-na-stroke` → base-7 | `#edeef0` | тонкие разделители |
| `ring-muted` / `divide-muted` | `--ui-color-divider-less` | `#f7f7f7` | |
| `ring-accented` / `divide-accented` | `--ui-color-divider-accent` | `#e2e2e2` | |
| `border-accented` | `--ui-color-accent-soft-element-blue` | `#0154c8` | акцентная рамка |

**Фон страницы в b24ui** не задаётся классом. На `<body>` библиотека ставит `background: var(--air-theme-background)`; в светлом режиме цвет `--air-theme-bg-color: var(--ui-color-gray-05)` = **`#eef2f4`**. **[b24ui]** (`src/templates.ts`, `003_b24_context_light.css`)

**Цвет основного текста body** библиотека не задаёт. В документации написано `text-legend`, но в исходниках этот класс у body убран (комментарий `@memo we remove text-legend` в `src/templates.ts`). Текст в компонентах задаёт сама тема: в полях `--ui-color-base-1` = `#333`, в подписях `text-label` и т. д.

### 2.3. Соответствие: наш токен → Air в b24ui

Отметки: «✓» — значение совпадает с токеном светлой темы b24ui; «≈» — близко, но не равно; «✗» — в b24ui другое значение или такого токена нет.

| Наш токен | Наше значение (**[Промт]**, синие — **[b24ui]**) | Ближайшее в b24ui **[b24ui]** | Значение в b24ui (light) | |
|---|---|---|---|---|
| `--brand` | `#0075ff` | `--ui-color-accent-main-primary` (цвет `air-primary`, класс `text-primary`) | `#0075ff` | ✓ |
| `--brand-hover` | `#0072f8` | отдельного токена нет; наведение = `color-mix(… 100%, #000 3%)` (`--ui-color-bg-state-hover-default`, `008_ui_global.css`) | вычисляется: `#0072f8` | ✓ |
| `--brand-ink` | `#0154c8` | `--ui-color-accent-main-link` / `--ui-color-accent-soft-element-blue` | `#0154c8` | ✓ |
| `--brand-soft` | `#e6f4ff` | у tinted-стиля фон — accent-soft-blue-2; `--ui-color-design-selection-bg` (= accent-soft-blue-3) | `#e6f4ff` / `#edf7ff` | ✓ / ≈ |
| `--page` | `#f1f4f6` | `--ui-color-bg-content-tertiary` (= gray-04). Фон body в b24ui при этом gray-05 | `#f1f4f6` / body `#eef2f4` | ✓ / ≈ |
| `--surface` | `#fff` | `--ui-color-bg-content-primary` (`bg-default`) | `#fff` | ✓ |
| `--surface-2` | `#fbfbfb` | `--ui-color-bg-content-secondary` (`bg-elevated`) | `#fbfbfb` | ✓ |
| `--line` | `#00000014` | `--ui-color-divider-vibrant-accent` | `#00000014` | ✓ |
| `--line-strong` | `#0000002e` | в светлом контексте нет; ближайший `--ui-color-divider-vibrant-accent-more`. Значение `#0000002e` в b24ui встречается только в контексте edge-light | `#00000026` | ✗ |
| `--ink` | `#1a1c1f` | `--ui-color-base-1` (текст полей, заголовок модалки) | `#333` | ✗ |
| `--ink-2` | `#525c69` | `--ui-color-base-2` → `text-label` | `#525c69` | ✓ |
| `--ink-3` | `#828b95` | `--ui-color-base-3` → `text-description`, `text-legend` | `#828b95` | ✓ |
| `--ok` | `#22ac43` | `--ui-color-accent-extra-grass` (в light `#24b547`, в dark и edge — ровно `#22ac43`); основной success `--ui-color-accent-main-success` | `#24b547` / `#1bce7b` | ≈ |
| `--wait` | `#af8b35` | `--ui-color-accent-extra-brown` (в light `#b89238`, в dark и edge — `#af8b35`); основной warning `--ui-color-accent-main-warning` | `#b89238` / `#faa72c` | ≈ |
| `--stop` | `#d1483f` | `--ui-color-accent-main-alert` (`air-primary-alert`) | `#ff5752` | ✗ |
| `--folder` | `#c98a2e` | аналога нет | — | ✗ |
| `--r` | `6px` | `--ui-border-radius-xs` (Tailwind `rounded-xs`). Но кнопка md в b24ui — 10px, поле md — 8px | `6px` | ✓ значение / ✗ применение |
| `--ui` | system-ui… | `--ui-font-family-system`: `system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Ubuntu, "Helvetica Neue", Arial, sans-serif` + эмодзи-шрифты | — | ≈ |
| `--mono` | ui-monospace… | `--ui-font-family-system-mono`: `ui-monospace, SFMono-Regular, "SF Mono", Consolas, "Liberation Mono", Menlo, monospace` | — | ≈ |

Файлы: `src/runtime/air-design-tokens/001_b24_global.css`, `003_b24_context_light.css`, `004…006_*`, `007_b24_global.css`, `src/runtime/index.css`.

**Вывод [Вывод]:** нейтральная часть (фоны, поверхности, линии, вторичный и третичный текст) и синий акцент совпадают с Air. Статусные цвета и основной текст в промте свои: они приглушённее и темнее, чем в Air.

**Если нужны все цвета Air, а не только синий [Вывод].** По умолчанию оставляйте набор из 2.1. Если заказчик просит «всё ровно как в Битрикс24», подключите после `b24-tokens.css` переопределение остальных цветов из b24ui (светлая тема). Синие уже совпадают с Air. Размеры и правила остаются из пути Б.

```css
/* Остальные цвета как в b24ui (Air, light). Подключать ПОСЛЕ b24-tokens.css. */
:root {
  --page:#eef2f4;                                      /* фон body в b24ui */
  --ink:#333;                                          /* base-1 */
  --ok:#24b547; --wait:#b89238; --stop:#ff5752;        /* grass / brown / alert */
}
```

**Если нужен прежний синий из промта [Промт].** В исходном промте синий был приглушённее: `#2c6ce0`. Он нужен, если проект уже сделан в этом цвете или требуется строгий контраст: белый текст на `#0075ff` даёт 4.21, чуть ниже нормы AA, а на `#2c6ce0` — 4.85 (раздел 9.3). Сам Битрикс24 использует `#0075ff` с тем же контрастом, поэтому по умолчанию берём официальный синий.

```css
/* Синий из исходного промта. Подключать ПОСЛЕ b24-tokens.css. */
:root {
  --brand:#2c6ce0; --brand-hover:#2259bd; --brand-ink:#1d539c; --brand-soft:#dde9fb;
}
```

### 2.4. Шкалы Air в b24ui (справочно, для пути А) **[b24ui]**

| Шкала | Значения | Файл |
|---|---|---|
| Радиусы `--ui-border-radius-*` (Tailwind `rounded-*`) | 3xs 2 · 2xs 4 · xs 6 · sm 8 · md 10 · lg 12 · xl 14 · 2xl 16 · 3xl 20 · pill 99rem · circle 50% | `001_b24_global.css`, `tw-style/radius.css` |
| Кегли `--ui-font-size-*` (Tailwind `text-*`) | 7xs 7 · 6xs 8 · 5xs 9 · 4xs 10 · 3xs 11 · xs 12 · sm 13 · md 14 · lg 15 · xl 16 · 2xl 18 · 3xl 22 · 4xl 24 · 5xl 28 (px) | `001_b24_global.css`, `tw-style/font-size.css` |
| Начертания | 100…950; рабочие 400 / 500 / 600 / 700 | `tw-style/font-weight.css` |
| Межстрочные | 3xs 1.2 · 2xs 1.3 · sm 1.35 · md 1.4 · lg 1.5 · xl 1.62 · 2xl 1.75 · 3xl 2 | `001_b24_global.css` |
| Отступы `--spacing-*` | 3xs 2 · 2xs 4 · 2xs2 6 · xs 8 · xs2 10 · sm 12 · sm2 14 · md 16 · md2 18 · lg 20 · lg2 22 · xl 24 · xl2 26 · 2xl 28 · 3xl 32 · 4xl 36 · 5xl 40 · 6xl 48 · 7xl 64 · 8xl 72 (px); базовый шаг Tailwind `--spacing: .25rem` | `tw-style/spacing.css`, `008_ui_global.css` |
| Толщина линий | thin 1px · medium 1.5px · thick 2px (рамки карточек и полей в Air — 1.5px) | `007_b24_global.css`, `003_b24_context_light.css` |
| Тень всплывающих окон | `--popup-window-box-shadow: 0 8px 17px 0 rgba(0,0,0,.21)` | `components/popup.css` |
| Шапка и контейнер | `--b24ui-header-height: 3.625rem`; `--b24ui-container-width: 90rem` | `src/runtime/index.css` |
| Типографика заголовков Air | h1 28/300 · h2 24/300 · h3 22/300 · h4 18/400 · h5 16/400 · h6 14/600 | `001_b24_global.css` (`--ui-typography-heading-*`) |

---

## 3. Типографика

### 3.1. Путь Б **[Промт]**

| Элемент | Значение | Класс в `b24-tokens.css` |
|---|---|---|
| База | `400 14px/1.5 var(--ui)`, интерфейс компактный (13–14px) | `body` |
| h1 | `21px / 650`, `letter-spacing:-.018em`, отступ снизу 18px | `h1`, `.h1` |
| Заголовок секции | `13.5px / 650` | `.section-title` |
| Кикер (h5, подписи групп) | `700 10.5px`, `uppercase`, `letter-spacing:.09em`, цвет `--ink-3` | `h5`, `.kicker` |
| Цифры, даты, размеры, длительности, ID | `--mono` + `font-variant-numeric:tabular-nums` | `.mono`, `.num` |
| ID рядом с заголовком | `500 12px mono`, `--ink-3` | `.id` |
| Крупные метрики | `17–25px / 650–700`, `letter-spacing:-.01…-.02em`; подпись над ними `10.5px uppercase 700 --ink-3` | `.metric-value` (22px/650/-.015em) + `.kicker` |
| Длинный текст | `max-width:74ch`, `13.4px`, `line-height:1.6`, `--ink-2` | `.longtext` |
| Шрифт | только системный, веб-шрифты не подключаем | — |

```html
<h1>Заявки <span class="id">#4821</span></h1>
<div class="kicker">Выручка за месяц</div>
<div class="metric-value">1 284 300 ₽</div>
<p class="longtext">Описание проекта…</p>
```

### 3.2. Путь А: что в b24ui **[b24ui]**

- Кегли — только из шкалы Air (раздел 2.4). Дробных размеров вроде 13.5px или 10.5px в b24ui нет. Начертания: 400, 500, 600, 700; значения 650 в шкале нет.
- Заголовок `B24Card`: 18px, 600 (`text-(length:--ui-font-size-2xl)`, `font-(--ui-font-weight-semi-bold)`). Заголовок `B24Modal`: 19px, 700 (`calc(18px + 1px)`). Описание модалки: 13px, `text-description`. (`src/theme/card.ts`, `modal.ts`)
- В коде шаблонов рецепты пишут размеры через переменные: `text-(length:--ui-font-size-sm)`, `font-(--ui-font-weight-semi-bold)`.
- Моноширинный шрифт: Tailwind-класс `font-b24-system-mono` (`--font-b24-system-mono` в `tw-style/font-family.css`). Табличные цифры — стандартный класс Tailwind `tabular-nums`. **[Вывод]**: в скилле этого правила нет, но и запрета нет.

---

## 4. Раскладка

### 4.1. Путь Б **[Промт]**

| Блок | Правило | Класс |
|---|---|---|
| Шапка | белая, `padding:11px 18px`, `border-bottom:1px solid var(--line)`. Действия справа обёрнуты **одним** блоком с `margin-left:auto`, а не автоотступом на каждой кнопке. Кнопки-переходы в шапке бледные (белые с рамкой), не синие | `.topbar`, `.topbar-actions` |
| Страница без меню | `padding:18px 20px 26px; max-width:1180px; margin:0 auto` | `.pane` |
| Страница с левым меню | `grid-template-columns:270px minmax(0,1fr)` | `.layout` |
| Левое меню | белое, `border-right:1px solid var(--line)` | `.side` |
| Пункт меню | `padding:7px 9px`, радиус 6px, `13.2px`, `--ink-2`; при наведении `--surface-2`; выбранный — brand-soft / brand-ink / 600 | `.side-item` (+ `.is-active` или `aria-current="page"`) |
| Счётчик в пункте | справа, mono 11px, `--ink-3` | `.side-count` |
| Группа меню | кикер 10px uppercase | `.side-group` |
| Узкий экран | при ширине ≤880px меню прячется | `@media (max-width:880px)` |
| Контент рядом с меню | `padding:24px 30px 40px` | `.content` |
| Сетка карточек | `repeat(auto-fit,minmax(258px,1fr))`, `gap:16px` | `.cards` |
| Ряд метрик | 3–4 колонки, `gap:10px` | `.metrics`, `.metrics.cols-3` |

Каркас страницы с меню (путь Б):

```html
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Заявки</title>
  <link rel="stylesheet" href="css/b24-tokens.css">   <!-- относительный путь, без ведущего «/» -->
  <link rel="stylesheet" href="css/style.css">
</head>
<body>
  <header class="topbar">
    <strong>Заявки</strong>
    <div class="topbar-actions">
      <a class="btn-ghost" href="reports.html">Отчёты</a>
      <button class="btn" type="button">Создать</button>
    </div>
  </header>
  <div class="layout">
    <nav class="side" aria-label="Разделы">
      <div class="side-group">Работа</div>
      <a class="side-item" href="#" aria-current="page">Входящие <span class="side-count">12</span></a>
      <a class="side-item" href="#">Архив <span class="side-count">230</span></a>
    </nav>
    <main class="content">
      <h1>Входящие</h1>
      <div class="cards">
        <section class="card">…</section>
        <section class="card">…</section>
      </div>
    </main>
  </div>
</body>
</html>
```

### 4.2. Путь А: раскладка в b24ui **[b24ui]**

Для приложения в Битрикс24 скилл рекомендует раскладку «dashboard» (`references/layouts/dashboard.md`):

```
B24App
└── NuxtLayout (dashboard)
    └── B24DashboardGroup
        ├── B24DashboardSidebar         (#header, #default — меню, #footer)
        └── NuxtPage
            └── B24DashboardPanel
                ├── #header → B24DashboardNavbar (+ B24DashboardToolbar для фильтров)
                ├── #body   (прокручиваемое содержимое)
                └── #footer (необязательно)
```

- Меню слева: `B24NavigationMenu orientation="vertical"` внутри `B24DashboardSidebar`. Внутри сворачиваемого меню обязательно передавать `:collapsed="collapsed"`. Группы задаются вложенными массивами `NavigationMenuItem[][]`.
- Пропсы `B24DashboardSidebar`: `resizable`, `collapsible`, `side` (`'left'`/`'right'`), `mode` на мобильных (`'slideover'` по умолчанию, `'modal'`, `'drawer'`).
- Содержимое кладётся в слот `#body`. Если положить его прямо в `B24DashboardPanel`, прокрутка не работает.
- Типичные ошибки (по скиллу): забыть `definePageMeta({ layout: 'dashboard' })` в Nuxt; не учитывать `collapsed`.
- Центрирующий контейнер: `B24Container` (ширина `--b24ui-container-width: 90rem`). Внутри `B24DashboardPanel` контейнер ставится в `#body`.
- Не злоупотреблять `B24Card`: «plain content with spacing is often better than wrapping everything in cards» (`component-selection.md`).

**Осторожно с высотой внутри портала [b24ui] + [Вывод].** В b24ui `B24DashboardGroup` — это `fixed inset-0`, `B24DashboardPanel` и `B24DashboardSidebar` — `min-h-svh`, а `B24Main` — `min-h-[calc(100vh-var(--b24ui-header-height))]` (`src/theme/dashboard-group.ts`, `dashboard-panel.ts`, `dashboard-sidebar.ts`, `main.ts`). Внутри iframe это означает «на всю высоту окна iframe». Подходит, если портал выделил окно фиксированной высоты и прокрутка идёт внутри. Не подходит для подгонки окна под содержимое (`BX24.fitWindow` / `resizeWindow`, раздел 7): высота «по окну» не даст окну сжаться. В живом портале эту связку мы не проверяли.

---

## 5. Компоненты

Формат каждого пункта: **Б** — значения и разметка для пути Б (классы из `b24-tokens.css`); **А** — компонент b24ui, ключевые пропсы и чем он отличается от пути Б. Пропсы сверены по `src/runtime/components/*.vue`, размеры — по `src/theme/*.ts`.

### 5.1. Карточка

**Б [Промт]:** `background:var(--surface); border:1px solid var(--line-strong)` (или `--line`); радиус 8–10px; `padding:14–16px`; **без теней**. Классы: `.card`, `.card.soft` (рамка `--line`). В CSS выбраны радиус 10px и отступ 16px.

```html
<section class="card">
  <h2 class="section-title">Загрузка</h2>
  …
</section>
```

**А [b24ui]:** `B24Card`, пропсы `title`, `description`, `variant`, `size` (`xs`/`sm`/`md`/`lg`); слоты `#header`, `#default`, `#footer`. По умолчанию `variant="outline"`, `size="md"`: белый фон, рамка **1.5px `#edeef0`**, радиус **10px**, отступ `p-xl` (24px), а с брейкпоинта `sm` — 22px по горизонтали и 16px по вертикали. Тени нет. Есть и другие варианты: `filled*`, `tinted*`, `outline-*`, `plain*`, `selection`. Если нужны иконка, бейдж или ссылки, берите `B24PageCard`.

```vue
<B24Card title="Загрузка" description="За последние 30 дней">…</B24Card>
<B24Card :b24ui="{ body: 'p-0' }">…</B24Card>   <!-- переопределение слота -->
```

### 5.2. Кнопки

**Б [Промт]:**
- Основная `.btn`: `600 13px`, `padding:9px 14px`, радиус `var(--r)`, фон `--brand`, белый текст, без рамки; наведение `--brand-hover`; disabled — `opacity:.55`; фокус — `outline:2px solid var(--brand); outline-offset:2px`. **На экране одна, максимум две синие.**
- Второстепенная `.btn-ghost`: прозрачный фон, `border:1px solid var(--line-strong)`, текст `--ink-2`, наведение `--surface-2`.
- Ряд кнопок `.btn-row`: `gap:8px`, прижат вправо.

```html
<div class="btn-row">
  <button class="btn-ghost" type="button">Отмена</button>
  <button class="btn" type="submit">Сохранить</button>
</div>
```

**А [b24ui]:** `B24Button`. Ключевые пропсы: `label`, `color`, `size`, `icon`, `trailingIcon`, `loading`, `loadingAuto`, `block`, `rounded`, `normalCase` (по умолчанию `true`, иначе подпись в верхнем регистре), `to`/`target` (кнопка-ссылка), `useDropdown`, `activeColor`.

- **Пропса `variant` у кнопки нет, вид задаётся только через `color`.** (`guidelines/forms.md`)
- **Цвет по умолчанию — `air-secondary-no-accent`, а не синий.** Основную кнопку надо явно пометить `color="air-primary"`. (`src/theme/button.ts`, `defaultVariants`)
- Палитра `color`: `air-primary`, `air-primary-success`, `air-primary-alert`, `air-primary-copilot`, `air-secondary`, `air-secondary-alert`, `air-secondary-accent`, `air-secondary-accent-1`, `air-secondary-accent-2`, `air-secondary-no-accent`, `air-tertiary`, `air-tertiary-accent`, `air-tertiary-no-accent`, `air-selection`, `air-boost`. Цвета `air-primary-warning` у кнопки **нет**, он есть у Badge и Alert.
- Как выбирать цвет (по `design-system.md`): главное действие — `air-primary`; отмена, назад, альтернатива — `air-secondary-no-accent` (в рецептах для «Отмена» часто `air-tertiary`); удаление — `air-primary-alert`; навигация и хром — `air-secondary-no-accent`. Зелёные кнопки-CTA не использовать: «Bitrix24 is moving away from green CTAs» (`recipes/overlays.md`).
- Размеры (высота / горизонтальный отступ / кегль / радиус): `xss` 20 / 8 / 10px / 5px · `xs` 24 / 7 / 12px / 6px · `sm` 28 / 9 / 13px / 8px · **`md` (по умолчанию) 34 / 13 / 14px·500 / 10px** · `lg` 38 / 17 / 15px / 10px · `xl` 46 / 23 / 16px / 10px (`components/button.css`).
- Неактивная кнопка — `opacity` 0.3 (в пути Б 0.55). Фокус — `focus-visible:outline-2 outline-offset-2`.

```vue
<div class="flex flex-wrap justify-end gap-2">
  <B24Button label="Отмена" color="air-tertiary" />
  <B24Button label="Сохранить" color="air-primary" type="submit" />
</div>
```

### 5.3. Кнопка-иконка

**Б [Промт]:** 27×27, радиус 6px, без рамки, цвет `--ink-3`; при наведении фон `--surface-2`, цвет `--ink`. Группы иконок разделяются вертикальной чертой 1×16px цвета `--line-strong`. Классы `.icon-btn`, `.icon-sep`. **У кнопки без текста обязателен `aria-label`.**

```html
<button class="icon-btn" type="button" aria-label="Обновить">
  <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 12a8 8 0 1 1-3-6.2M20 4v5h-5"/></svg>
</button>
<span class="icon-sep"></span>
```

**А [b24ui]:** `B24Button` только с иконкой. Тихий вариант: `color="air-tertiary-no-accent"`, `aria-label` обязателен (`guidelines/forms.md`):

```vue
<B24Button :icon="SettingsIcon" color="air-tertiary-no-accent" size="sm" aria-label="Настройки" />
```

### 5.4. Поле ввода и поиск

**Б [Промт]:** высота 38px, фон `--surface-2`, `border:1px solid var(--line-strong)`, радиус 6px, кегль 13.5px; плейсхолдер `--ink-3`; фокус `outline:2px solid var(--brand); outline-offset:-1px`. Лупа — SVG внутри поля слева (`left:11px`, у поля `padding-left:32px`). Классы `.input`, `.search`.

```html
<label class="search">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>
  <input class="input" type="search" placeholder="Поиск" aria-label="Поиск">
</label>
```

**А [b24ui]:** `B24Input`, пропсы `icon` (иконка слева), `trailingIcon`, `placeholder`, `type`, `size`, `color`, `noBorder`, `underline`, `rounded`, `highlight`, `tag`, `disabled`, `v-model`. Размер `md` (по умолчанию): **высота 34px, кегль 15px, радиус 8px**; размер `lg` — 38px. Фон белый, обводка 1px `#edeef0` (`ring-inset`), при фокусе и наведении она перекрашивается в цвет `color` (по умолчанию `air-primary`). Плейсхолдер `#c9ccd0` (`src/theme/input.ts`). Поля оборачиваются в `B24FormField` (подпись, подсказка, ошибка) внутри `B24Form`, подробно в `guidelines/forms.md`.

```vue
<B24Input v-model="search" :icon="SearchIcon" placeholder="Поиск…" />
```

Что выбрать для поля (по `component-selection.md`): короткий фиксированный список — `B24Select`; длинный или с поиском — `B24SelectMenu`; автодополнение со свободным вводом — `B24InputMenu`; многострочный текст — `B24Textarea`; число — `B24InputNumber`; дата — `B24InputDate`; да/нет — `B24Switch` или `B24Checkbox`.

### 5.5. Чип / фильтр

**Б [Промт]:** `500 11.5px`, `padding:6px 9px`, `radius:100px`, рамка `--line-strong`, фон `--surface-2`, текст `--ink-2`. Включённый чип: brand-soft / brand-ink, рамка — `color-mix(in srgb, var(--brand) 30%, transparent)`. Класс `.chip`, состояние — `aria-pressed="true"` или `.is-on`.

```html
<button class="chip" type="button" aria-pressed="true">Мои</button>
<button class="chip" type="button" aria-pressed="false">Срочные</button>
```

**А [b24ui] + [Вывод]:** отдельного компонента «фильтр-чип» нет. **`B24Chip` — это другое**: точка-уведомление поверх аватара или иконки. В рецепте `task-form.md` переключаемые кнопки-опции сделаны так: `B24Button size="sm"`, у активной `color="air-secondary-accent-2"`, у неактивной `color="air-secondary-no-accent"`. Для «таблетки» добавьте `rounded`. Метки внутри поля — `B24InputTags`.

### 5.6. Пилюля статуса

**Б [Промт]:** `600 11px`, `padding:5px 9px`, `radius:100px`, перед текстом точка 6px цвета `currentColor`; текст статусного цвета, фон — 10% этого цвета, рамка — 40%. Класс `.pill` + `.ok` / `.wait` / `.stop` (без модификатора — нейтральный `--ink-3`).

```html
<span class="pill ok">Готово</span>
<span class="pill wait">Ожидание</span>
<span class="pill stop">Ошибка</span>
```

**А [b24ui]:** `B24Badge`, пропсы `label`, `color`, `size`, `inverted`, `square`, `icon`/`leadingIcon`, `useClose`. По умолчанию `color="air-primary"`, `size="md"`: **высота 20px, отступ 8px, 12px жирный, радиус = половина высоты**, заливка сплошная. Статусные цвета: `air-primary-success`, `air-primary-warning`, `air-primary-alert`, нейтральный — `air-tertiary`. Мягкий вид, близкий к нашей пилюле (белый фон, цветные текст и рамка 1px), даёт `inverted` для цветов `air-primary*`: в `src/theme/badge.ts` он переключает бейдж на стили `style-filled-*-inverted`. Как это выглядит на экране, мы не проверяли.

```vue
<B24Badge :label="row.status" :color="row.status === 'Active' ? 'air-primary-success' : 'air-tertiary'" />
```

### 5.7. Вкладки

**Б [Промт]:**
- Вкладки с подчёркиванием `.tabs > .tab`: `600 13px`, `padding:8px 14px`, `border-bottom:2px solid transparent`, `margin-bottom:-1px`; под рядом общая линия `--line`. Активная вкладка — текст `--brand-ink`, подчёркивание `--brand`. Состояние: `aria-selected="true"` или `.is-active`.
- Вкладки-кнопки (переключатель видов) `.seg > button`: `600 12.5px`, `padding:8px 13px`, белые с рамкой; включённая — brand-soft / brand-ink.

```html
<div class="tabs" role="tablist">
  <button class="tab" role="tab" aria-selected="true">Список</button>
  <button class="tab" role="tab" aria-selected="false">История</button>
</div>
<div class="seg" role="group" aria-label="Вид">
  <button type="button" aria-pressed="true">Таблица</button>
  <button type="button" aria-pressed="false">Канбан</button>
</div>
```

**А [b24ui]:** `B24Tabs`, пропсы `items` (`label`, `icon`, `badge`, `slot`, `content`, `value`, `disabled`), `variant` (единственный вариант `'link'`, он же по умолчанию), `orientation`, `size`, `content` (поставьте `:content="false"`, если нужен только ряд вкладок без панелей), `v-model`. Размер `md`: `px-3 py-1.5`, 14px, начертание 500. **Индикатор 1px**, у нас 2px (`src/theme/tabs.ts`). Если у вида должен быть свой адрес, используйте навигацию через `to` (`component-selection.md`). Переключатель видов **[Вывод]**: `B24FieldGroup` (по документации — «Organize several button-like elements into a group») с `B24Button` внутри.

```vue
<B24Tabs :items="[{ label: 'Обзор', slot: 'overview' }, { label: 'История', slot: 'history' }]">
  <template #overview>…</template>
  <template #history>…</template>
</B24Tabs>
```

### 5.8. Таблица-список

**Б [Промт]:** это не сетка Excel. Внешняя рамка `--line-strong`, радиус 10px, `overflow:hidden`; вертикальных линий нет; между строками линия `--line`; строка при наведении `--surface-2`. Шапка: `11px uppercase`, `letter-spacing:.04em`, фон `--surface-2`, текст `--ink-2`. Ячейки `padding:9px 10px`. Числовые колонки — mono. **Стрелка сортировки — SVG-шеврон, не CSS-треугольник.** Классы `.table-wrap > .table`, `.num`, `.th-sort`.

```html
<div class="table-wrap"><div class="table-scroll">
  <table class="table">
    <thead><tr>
      <th><button class="th-sort" type="button">Заявка
        <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button></th>
      <th>Статус</th><th class="num">Сумма</th>
    </tr></thead>
    <tbody>
      <tr><td>Поставка №12</td><td><span class="pill ok">Готово</span></td><td class="num">12 400</td></tr>
    </tbody>
  </table>
</div></div>
```

**А [b24ui]:** `B24Table` (на TanStack Table v8). Пропсы: `data`, `columns` (`accessorKey`, `header`, `cell`), `loading`, `sticky`, `empty`, `v-model:sorting`, `v-model:row-selection`, `v-model:pagination`. Слоты `#<колонка>-cell`, `#<колонка>-header`, `#empty`; данные строки лежат в `row.original`. Вид по умолчанию: шапка высотой 45px, `px-4 py-2`, **14px обычного начертания, не капсом**; ячейки `p-4`; разделители `#f0f0f0`. Подсветка при наведении есть только у выбираемых строк (`src/theme/table.ts`). Простую HTML-таблицу оформляет `B24TableWrapper`. Пагинация — `B24Pagination` (в `total` передаётся число **записей**, а не страниц). Полный рецепт с фильтрами, выбором строк и пагинацией — `references/recipes/data-tables.md`.

### 5.9. Прогресс-бар

**Б [Промт]:** высота 5–8px, `radius:100px`, дорожка `--line` или `--surface-2`, заливка `--brand` или статусного цвета. Класс `.progress > span` (в CSS высота 6px), модификаторы `.ok`, `.wait`, `.stop`. В разметке — `role="progressbar"` и `aria-valuenow`.

```html
<div class="progress" role="progressbar" aria-valuenow="64" aria-valuemin="0" aria-valuemax="100"><span style="width:64%"></span></div>
```

**А [b24ui]:** `B24Progress`, пропсы `v-model`/`model-value`, `max`, `color` (по умолчанию `air-primary`), `size`, `status`, `animation`, `orientation`. Высота: `xs` 2px · `sm` 4px · **`md` 8px** · `lg` 12px. Дорожка `#c9ccd0` (`src/theme/progress.ts`). Сегментный прогресс с легендой — `B24ProgressGroup`.

### 5.10. Аватар

**Б [Промт]:** круг 26–28px, фон `--brand-soft`, текст `--brand-ink`, инициалы `700 10.5px`. Класс `.avatar` (в CSS 28px).

**А [b24ui]:** `B24Avatar`, пропсы `src`, `alt`, `text`, `icon`, `size` (`3xs`…`3xl`; `sm` 28px, **`md` 32px — по умолчанию**), `color` (по умолчанию `air-secondary-no-accent` — белый круг с обводкой), `chip`. Группа аватаров — `B24AvatarGroup :max="N"`. Аватар с именем и подписью — `B24User`.

### 5.11. Хлебные крошки

**Б [Промт]:** белый блок высотой 38px с рамкой. Сегмент: `padding:3px 6px`, радиус 5px; текущий сегмент — `--ink`, 600; разделитель — `--ink-3`, 11px. Класс `.crumbs`, текущий сегмент помечается `aria-current="page"`.

```html
<nav class="crumbs" aria-label="Путь">
  <a href="#">Диск</a><span class="sep">/</span><a href="#">Проекты</a><span class="sep">/</span>
  <span class="crumb" aria-current="page">Отчёт</span>
</nav>
```

**А [b24ui]:** `B24Breadcrumb`, пропсы `items` (`label`, `icon`, `to`, `slot`), `separatorIcon`, `color`. Ссылки 13px; текущая — 600, цвет акцента; остальные — `text-legend`, 500; разделитель — иконка 20px `#dfe0e3`. Белого блока с рамкой у компонента нет (`src/theme/breadcrumb.ts`).

### 5.12. Пустое состояние

**Б [Промт]:** по центру, `padding:60px 20px`; заголовок `--ink-2`, 14px, жирный; текст 12.8px `--ink-3`, `max-width:44ch`. Классы `.empty`, `.empty-title`.

```html
<div class="empty">
  <svg class="ico ico-lg" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h6l2 2h10v10H3z"/></svg>
  <div class="empty-title">Пока пусто</div>
  <p>Здесь появятся файлы, когда их загрузят.</p>
</div>
```

**А [b24ui]:** `B24Empty`, пропсы `icon`, `title`, `description`, `actions` (массив пропсов `B24Button`), `size`, `color` (по умолчанию `air-secondary-accent`), `loading`. Пустое состояние внутри таблицы — слот `#empty` у `B24Table`. Внутри панели без рамки (рецепт `detail-panel.md`): `color="air-tertiary" :b24ui="{ root: 'ring-0 rounded-none' }"`.

### 5.13. Предупреждение

**Б [Промт]:** текст `--wait`, фон wait 12%, рамка wait 30%, радиус 6px, `padding:9px 11px`, 12px. Класс `.warn`.

```html
<div class="warn" role="status">
  <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4l9 16H3z"/><path d="M12 10v4"/></svg>
  <span>Две заявки без ответственного.</span>
</div>
```

**А [b24ui]:** `B24Alert`, пропсы `title`, `description`, `icon`, `color`, `inverted`, `orientation`, `size`, `actions`, `close`. Радиус 10px. Цвета: `air-primary`, `air-primary-success`, `air-primary-alert`, `air-primary-warning`, `air-primary-copilot`, `air-secondary`, `air-secondary-alert`, `air-secondary-accent` (по умолчанию), `air-secondary-accent-1`, `air-secondary-accent-2`, `air-tertiary`. Мягкое предупреждение **[Вывод]**: `color="air-primary-warning" inverted` (белый фон, цветные рамка и текст; `inverted` работает только для `air-primary*`). Как выбирать между уведомлениями (`component-selection.md`): `B24Alert` — постоянное сообщение в контексте; тост — короткий отклик на действие; `B24Banner` — объявление на всё приложение. **Через тост нельзя сообщать то, на что пользователь должен отреагировать.**

### 5.14. Модалка

**Б [Промт]:** белая, рамка `--line-strong`, радиус 10px, `box-shadow:0 24px 60px #00000040` (одно из немногих мест с тенью), ширина 720px, подложка `#00000059`, появление через opacity и `scale(.97→1)` за 0,16 с. Классы `.modal-backdrop > .modal` (`.modal-head`, `.modal-body`, `.modal-foot`). Показ — снять `hidden` с `.modal-backdrop`. Внутри iframe см. раздел 7: `position:fixed` там ненадёжен.

```html
<div class="modal-backdrop" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="dlg-title">
    <div class="modal-head"><h2 class="section-title" id="dlg-title">Новая заявка</h2>
      <button class="icon-btn" type="button" aria-label="Закрыть">…</button></div>
    <div class="modal-body">…</div>
    <div class="modal-foot btn-row"><button class="btn-ghost" type="button">Отмена</button><button class="btn" type="submit">Создать</button></div>
  </div>
</div>
```

Поведение, которое нужно дописать в JS: закрытие по Esc и по клику на подложку, перевод фокуса в модалку и возврат фокуса после закрытия. **[Вывод]**

**А [b24ui]:** `B24Modal`, пропсы `v-model:open`, `title`, `description`, `close`, `dismissible`, `fullscreen`, `scrollable`, `overlay`, `transition`; слоты `#body`, `#footer` (в нём доступен `{ close }`), `#content`. Вид: максимальная ширина **32rem (512px)**, радиус **18px** (`2xl` + 2px), `shadow-lg`; подложка `#003366` с прозрачностью 20% и `backdrop-blur-sm` (с учётом `motion-safe`); анимация `scale-in` за 200 мс из `scale(.95)` (`src/theme/modal.ts`, `keyframes.css`). Модалку из кода открывает `useOverlay()`. Для деталей и настроек лучше подходит боковая панель `B24Slideover`, для мобильных — `B24Drawer`. Пропса `mode="drawer"` у Modal и Slideover нет. Рецепты — `references/recipes/overlays.md`.

```vue
<B24Modal v-model:open="isOpen" title="Удалить заявку" description="Действие нельзя отменить." :b24ui="{ footer: 'justify-end' }">
  <template #footer="{ close }">
    <B24Button label="Удалить" color="air-primary-alert" @click="remove" />
    <B24Button label="Отмена" color="air-tertiary" @click="close" />
  </template>
</B24Modal>
```

### 5.15. Тост

**Б [Промт]:** внизу по центру, фон `--ink`, текст `--page`, `600 12.5px`, `radius:100px`, `padding:9px 16px`. Класс `.toast` (в CSS отступ снизу 20px, в промте не задан). В разметке — `role="status"`, чтобы экранный диктор его прочитал.

**А [b24ui]:** `useToast().add({ title, description, color, icon, duration, actions })`, а также `toast.remove(id)` и `toast.clear()`. Нужна обёртка `B24App`. Значения по умолчанию в `Toaster.vue`: позиция **`top-right`**, `duration: 5000`, `max: 5`, `progress: true`. Вид тоста: тёмный (`bg-black/80`), радиус 8px, `p-4`, 13px. Внизу по центру, как в пути Б: `<B24App :toaster="{ position: 'bottom-center' }">`.

### 5.16. Выпадающий список, меню действий

**Б [Промт]:** белый, радиус 10px, `box-shadow:0 10px 30px #00000022`; пункты `padding:8px 10px`, радиус 6px; группы подписаны кикером. Классы `.menu`, `.menu-item`; выбранный пункт — `aria-selected="true"` или `.is-active` (brand-soft / brand-ink / 600).

**А [b24ui]:** меню действий — `B24DropdownMenu` (`items`, вложенные массивы дают группы с разделителями; у пункта есть `label`, `icon`, `color`, `onSelect`, `kbds`, `to`). Контекстное меню — `B24ContextMenu`. Подсказки при вводе — `B24InputMenu`. Список с поиском — `B24SelectMenu`. Глобальный поиск — `B24CommandPalette`. Вид меню: радиус **14px**, тень `0 8px 17px 0 rgba(0,0,0,.21)`, отступ сверху и снизу 10px, высота не больше `--max-height-popup-menu: 40vh` (`src/theme/dropdown-menu.ts`, `components/popup.css`).

### 5.17. Сводная таблица «элемент → путь Б → путь А»

| Элемент | Путь Б (класс) | Путь А (компонент) |
|---|---|---|
| Шапка | `.topbar` + `.topbar-actions` | `B24DashboardNavbar` / `B24Header` |
| Страница | `.pane`, `.layout`, `.content` | `B24DashboardPanel`, `B24Container` |
| Левое меню | `.side`, `.side-item`, `.side-count`, `.side-group` | `B24DashboardSidebar` + `B24NavigationMenu orientation="vertical"` |
| Карточка | `.card` | `B24Card`, `B24PageCard` |
| Кнопка основная / второстепенная | `.btn` / `.btn-ghost` | `B24Button color="air-primary"` / `color="air-secondary-no-accent"` |
| Кнопка-иконка | `.icon-btn`, `.icon-sep` | `B24Button :icon color="air-tertiary-no-accent" aria-label` |
| Поле, поиск | `.input`, `.search` | `B24Input` (+ `B24FormField`) |
| Чип-фильтр | `.chip` | `B24Button size="sm"` (переключаемые цвета), `B24InputTags` |
| Статус | `.pill.ok/.wait/.stop` | `B24Badge` |
| Вкладки / переключатель видов | `.tabs .tab` / `.seg` | `B24Tabs` / `B24FieldGroup` + `B24Button` |
| Таблица | `.table-wrap .table` | `B24Table`, `B24TableWrapper` |
| Прогресс | `.progress` | `B24Progress` |
| Аватар | `.avatar` | `B24Avatar`, `B24AvatarGroup`, `B24User` |
| Крошки | `.crumbs` | `B24Breadcrumb` |
| Пусто | `.empty` | `B24Empty` |
| Предупреждение | `.warn` | `B24Alert` |
| Модалка | `.modal-backdrop .modal` | `B24Modal` (`B24Slideover`, `B24Drawer`) |
| Тост | `.toast` | `useToast()` |
| Меню, подсказки | `.menu .menu-item` | `B24DropdownMenu`, `B24InputMenu`, `B24SelectMenu` |
| Загрузка | — | `B24Skeleton` |

---

## 6. Иконки

### 6.1. Путь Б: инлайн-SVG **[Промт]**

- Только инлайн-SVG в стиле линий: `viewBox="0 0 24 24" fill="none"`, `stroke="currentColor" stroke-width="1.8"`, скругления `stroke-linejoin="round"`.
- Размер 14–18px в строках и кнопках, 26–30px в крупных карточках.
- Цвет берётся из `color` родителя. Папки окрашиваются в `--folder`, файлы — в `--ink-3`.
- **Эмодзи и иконочные шрифты не используем.**
- Декоративной иконке ставится `aria-hidden="true"`. **[Вывод]**

```html
<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
  <path d="M3 7h6l2 2h10v10H3z"/>
</svg>
<!-- В b24-tokens.css: .ico 16px, .ico-lg 28px, .ico-folder, .ico-file -->
```

### 6.2. Путь А: `@bitrix24/b24icons-vue` **[b24ui]**

- Пакет ставится вместе с библиотекой (`pnpm add @bitrix24/b24ui-nuxt @bitrix24/b24icons-vue tailwindcss`). В нём больше 1400 иконок.
- **Каталог:** https://bitrix24.github.io/b24icons/icons/
- **Метаданные для поиска** (в установленном проекте): `node_modules/@bitrix24/b24icons-vue/dist/info-metadata.json`. Ищите по части имени без учёта регистра и берите поля `code`, `type` и `icon` (`guidelines/icons.md`).
- **Импорт — только по умолчанию, без фигурных скобок**, путь `@bitrix24/b24icons-vue/<папка>/<ИмяIcon>`:

```vue
<script setup>
import SearchIcon from '@bitrix24/b24icons-vue/outline/SearchIcon'
import TrashcanIcon from '@bitrix24/b24icons-vue/outline/TrashcanIcon'
import PersonIcon from '@bitrix24/b24icons-vue/main/PersonIcon'
</script>

<template>
  <SearchIcon class="size-5" />
  <B24Button :icon="TrashcanIcon" color="air-primary-alert" label="Удалить" />
</template>
```

- В пропс `:icon` передаётся **сам импортированный компонент**. Не строка и не объект: `:icon="{ X }"` не работает. Имена в стиле Iconify (`i-lucide-…`) здесь ничего не дают (`layouts/landing.md`).
- Папки, которые встречаются в примерах скилла и документации: `outline`, `main`, `actions`, `button`, `common-b24`, `common-service`, `crm`, `social`, `file-type`, `editor`, `animated`.
- Примеры имён из скилла: `outline/HomeIcon`, `outline/SettingsIcon`, `outline/PlusLIcon`, `outline/SearchIcon`, `outline/CalendarIcon`, `outline/CircleCheckIcon`, `outline/TrashcanIcon`, `outline/DownloadIcon`, `outline/ShareIcon`, `main/MenuIcon`, `main/TrashBinIcon`, `actions/PencilDrawIcon`, `main/WarningIcon`, `outline/AlertIcon`, `outline/InfoCircleIcon`, `common-b24/UserGroupIcon`.

---

## 7. Внутри Битрикс24 (iframe)

Приложение в портале открывается во фрейме. Отсюда особые правила.

### 7.1. Правила **[Промт]**

1. **Никаких `100vh` и `height:100%` «на весь экран»**: внутри iframe это чужая высота. Высоту страницы подгоняет портал. Подключите `<script src="//api.bitrix24.tech/api/v1/"></script>` и вызывайте `BX24.fitWindow()`, повторяя вызов через `ResizeObserver` на `body`. **Исключение — приложение, открытое через VibeCode:** там SDK `BX24` не работает, высоту передают иначе (см. ниже, «Приложение VibeCode»).
2. **Длинные панели** (чат, дерево, список) делаются фиксированной высоты в px с прокруткой внутри (`.scroll-panel`), иначе вся страница раздувается.
3. **Элементы с `position:fixed` ведут себя ненадёжно**: панели действий делайте обычным блоком в потоке страницы.
4. **`[hidden]` не скрывает элемент, у которого задан свой `display`.** Показывайте так: `.x:not([hidden]){display:flex}`. Весь `b24-tokens.css` написан по этому правилу.

Адрес скрипта: официальная страница BX24 JS SDK (apidocs.bitrix24.ru, 18.11.2026) показывает `//api.bitrix24.tech/api/v1/`; прежний `//api.bitrix24.com/api/v1/` из промта и учебных шаблонов на ту же дату тоже отвечает. В новых проектах берите адрес из официальной документации.

**Классическое приложение Битрикс24** (локальное или Маркетплейс, свой обработчик). Минимальный код (наш вариант правила 1; `BX24.init` — стандартная точка входа библиотеки, она используется в учебных шаблонах):

```html
<script src="//api.bitrix24.tech/api/v1/"></script>
<script>
  if (window.BX24) {
    BX24.init(function () {
      var pending = false;
      function fit() {                       // не чаще одного раза за кадр
        if (pending) return; pending = true;
        requestAnimationFrame(function () { pending = false; BX24.fitWindow(); });
      }
      fit();
      new ResizeObserver(fit).observe(document.body);
    });
  }
</script>
```

**Приложение VibeCode** (OAuth-приложение платформы vibecode.bitrix24.tech — и на её хостинге, и с `appUrl` на вашем сервере). **[VC]** Портал открывает такое приложение через обработчик платформы, после перенаправления `BX24.init` **не завершается** — SDK ждать нельзя, иначе страница зависнет на «Подключение к порталу…». Высоту фрейма платформа подгоняет сама, если: (1) в карточке приложения включена «Авто-высота iframe» (или `placementResizeEnabled: true` в `PATCH /v1/apps/:id`); (2) приложение сообщает свою высоту родительскому окну; (3) в CSP `frame-ancestors` есть `https://vibecode.bitrix24.tech`. Возможность раскатывается по порталам постепенно. Подробности — `bitrix24-apps.md`, раздел 4.2.

```html
<script>
  // Высота для обёртки VibeCode: число пикселей, targetOrigin именно '*'.
  (function () {
    var pending = false;
    function report() {
      if (pending) return; pending = true;
      requestAnimationFrame(function () {
        pending = false;
        var height = document.documentElement.scrollHeight;
        window.parent.postMessage({ type: 'vibe:resize', height: height }, '*');
      });
    }
    report();
    new ResizeObserver(report).observe(document.documentElement);
  })();
</script>
```

### 7.2. Что известно сверх промта

- **[Шаблон]** В учебном шаблоне «Битрикс24: расширенное приложение» (`www/js/frame.js`) от `fitWindow` отказались. Причина в комментарии: штатный `BX24.fitWindow()` «растягивает окно на всю страницу и в карточке сделки выглядит сломанным». Шаблон вызывает `BX24.resizeWindow(ширина, высота)`, а высоту считает по нижней границе видимых элементов, не по `scrollHeight`. Он не сжимается ниже исходной высоты окна (не меньше 320px), не трогает высоту, пока открыта модалка, и следит за `hidden`/`class`/`style` через `MutationObserver`. Если приложение встраивается в карточку CRM, берите этот вариант. В живом портале мы его в рамках этого справочника не перепроверяли.
- **[Шаблон]** Там же объяснено, почему важно правило 1: `min-height:100vh` внутри портала равен высоте окна, которую приложение само и заказало. Из-за этого окно перестаёт сжиматься, и под содержимым остаётся белая полоса.
- **[Вывод]** Тост и модалка из раздела 5 используют `position:fixed`. Если окно растянуто под содержимое, «низ окна» может оказаться далеко за пределами видимой области, и тост внизу по центру никто не увидит. Внутри портала безопаснее показывать сообщение рядом с местом действия, в потоке страницы. Модалку проверяйте в реальном портале.
- **[b24ui]** Ни в исходниках, ни в документации b24ui нет кода для BX24, `fitWindow` или `resizeWindow`: высоту фрейма библиотека сама не подгоняет. Для работы с порталом документация ссылается на отдельный JS SDK: https://bitrix24.github.io/b24jssdk/.
- **[b24ui]** Особого режима «внутри фрейма» у `B24App` нет. Его пропсы: `locale`, `toaster`, `tooltip`, `portal` (по умолчанию `'body'`) и параметры ConfigProvider (`dir`, `scrollBody`, `nonce`) (`src/runtime/components/App.vue`).
- **[b24ui]** Платформа определяется по User-Agent. Nuxt-плагин `platform.ts` ставит на `<html>` атрибуты `data-platform="bitrix-mobile" | "bitrix-desktop" | "web"` и `data-version`. Для этих платформ подключаются отдельные токены (`*_mobile_*.css`, `*_desktop_*.css`), в Tailwind доступны варианты `bitrix-mobile:`, `bitrix-desktop:`, `web:`, в коде — `useDevice()` (`isBitrixMobile`, `isBitrixDesktop`, `isWeb`, `screen`). Плагин регистрируется только в Nuxt-модуле (`src/module.ts`). Ставит ли кто-то этот атрибут в Vue-сборке без Nuxt, по исходникам не видно, и мы это не проверяли.
- **[b24ui] + [Вывод]** Раскладка dashboard в b24ui рассчитана на высоту окна (раздел 4.2). Внутри портала выберите одну стратегию: либо окно фиксированной высоты с прокруткой внутри (подходит dashboard), либо окно, подогнанное под содержимое (тогда без `B24DashboardGroup`/`B24Main` и с подгонкой высоты из 7.1).

---

## 8. Тёмная тема

- **[Промт] Правило для наших приложений: только светлая тема.** Никаких `@media (prefers-color-scheme: dark)` и переключателей темы.
- **[b24ui] Библиотека тёмную тему поддерживает.** Режимов пять: `light` (по умолчанию), `dark`, `edge-light`, `edge-dark` (оба с фоновым изображением) и `auto` (по `prefers-color-scheme`). Для каждого есть свои файлы токенов (`003…006_b24_context_*.css`), отдельно для десктоп- и мобильного приложения (`*_desktop_dark.css`, `*_mobile_dark.css`). Компоненты-переключатели: `B24ColorModeButton`, `B24ColorModeSwitch`, `B24ColorModeSelect`, `B24ColorModeAvatar`, `B24ColorModeImage`. В коде — `useColorMode()`.
- **[b24ui] Режим по умолчанию — светлый, за системой он не следует**, пока не выбрано `auto`: в `useColorMode.ts` стоит `colorModeInitialValue || 'light'`.
- **Как закрепить светлую тему в пути А [b24ui]:**
  - Nuxt: `b24ui: { colorMode: false }` в `nuxt.config.ts` (тогда `useColorMode()` возвращает заглушку с `forced: true`). Либо оставить интеграцию включённой и не ставить переключатели: начальный режим и так `light`.
  - Vue: `bitrix24UIPluginVite({ colorMode: false })` (опция есть в `src/unplugin.ts`).
  - Не добавлять `B24ColorMode*` и не ставить `colorModeInitialValue: 'auto'`.
- **Про сам Битрикс24.** В промте написано «в Битрикс24 её нет». Наши источники этого не подтверждают и не опровергают. В b24ui есть файл токенов `024_b24_desktop_dark.css` для десктоп-приложения в тёмном режиме, то есть библиотека такой контекст предусматривает. Правило «только светлая» — наше решение по стилю, а не утверждение о портале.

---

## 9. Анимации и доступность

### 9.1. Анимации

- **[Промт]** Только короткие переходы 0,12–0,18 с с `ease`, и только по `opacity`, `transform` и цвету. В `b24-tokens.css` везде 0,15 с, у модалки и тоста 0,16 с.
- **[Промт]** Обязательно добавить:
  ```css
  @media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}
  ```
- **[b24ui]** Библиотека в режиме reduced-motion поступает мягче: появление через прозрачность оставляет, а масштаб и сдвиг убирает (переопределяет `@keyframes scale-in` в `keyframes.css`). В темах используются варианты `motion-safe:` и `motion-reduce:`. Длительности: модалка 200 мс, меню 100 мс, индикатор вкладок 200 мс.

### 9.2. Фокус и клавиатура

- **[Промт]** Фокус: `outline:2px solid var(--brand); outline-offset:2px` у кнопок; у полей — `outline-offset:-1px`. В `b24-tokens.css` то же правило распространено на все интерактивные классы через `:focus-visible`.
- **[Вывод]** У кнопок-иконок обязателен `aria-label`; у переключателей — `aria-pressed`; у вкладок — `role="tab"` и `aria-selected`; у модалки — `role="dialog"`, `aria-modal="true"` и заголовок через `aria-labelledby`; у текущего пункта меню или крошки — `aria-current="page"`; у тоста — `role="status"`.
- **[b24ui]** Компоненты построены на Reka UI: ARIA-атрибуты, клавиатурная навигация и управление фокусом встроены (раздел Introduction документации). У подписей полей это решает `B24FormField` (`aria-describedby` указывает только на реально показанные блоки — `guidelines/forms.md`).

### 9.3. Контраст (посчитано по формуле WCAG 2.x) **[Вывод]**

| Пара | Контраст | WCAG AA (4.5:1 для обычного текста) |
|---|---|---|
| `--ink` на белом | 17.08 | ✓ |
| `--ink-2` на белом | 6.79 | ✓ |
| `--ink-3` на белом / на `--page` | 3.46 / 3.13 | ✗ (подходит для крупного текста от 3:1) |
| белый на `--brand` (кнопка) | 4.21 | ✗ (на грани; так же в самом Битрикс24) |
| `--brand` на белом (ссылка) | 4.21 | ✗ (на грани) |
| `--brand-ink` на `--brand-soft` (выбранное) | 6.04 | ✓ |
| `--page` на `--ink` (тост) | 15.46 | ✓ |
| `--stop` на белом / на подмешке 10% | 4.46 / 3.90 | ✗ (на грани) |
| `--ok` на подмешке 10% (пилюля) | 2.69 | ✗ |
| `--wait` на подмешке 12% (`.warn`) | 2.84 | ✗ |
| для сравнения, белый на синем промта `#2c6ce0` | 4.85 | ✓ |

Что из этого следует:

- `--ink-3` годится для дат, счётчиков и кикеров, но не для важного текста, который нужно прочитать.
- Официальный синий `#0075ff` чуть не дотягивает до AA с белым текстом — так устроен сам Битрикс24, и это допустимо. Если проекту нужен строгий AA, подключите синий из промта (конец раздела 2.3).
- Статус нельзя передавать только цветом. У пилюли всегда есть слово («Готово»), у предупреждения — текст и иконка.
- Если проекту нужен строгий AA, текст статусов можно затемнить, не вводя новых литералов: `color: color-mix(in srgb, var(--ok) 70%, #000)`. При 70% контраст на подмешке 12% получается 4.90 для ok, 5.20 для wait и 6.55 для stop. **Это отступление от промта**, его согласовывают отдельно.

---

## 10. Путь А подробно: `@bitrix24/b24ui-nuxt`

Библиотека основана на Nuxt UI v4, Reka UI, Tailwind CSS v4 и Tailwind Variants, в ней больше 130 компонентов, лицензия MIT. Работает в Nuxt, Vue (Vite), Laravel (Vite + Inertia) и AdonisJS (Vite + Inertia). Требования движка из `package.json`: Node `^20.19.0 || >=22.12.0`. Работает **только с Tailwind CSS**, UnoCSS не поддерживается. **[b24ui]**

### 10.1. Быстрый старт из официального шаблона **[b24ui]**

```bash
# Nuxt
git clone https://github.com/bitrix24/templates-dashboard.git <project-name>   # dashboard
git clone https://github.com/bitrix24/starter-b24ui.git <project-name>         # минимальный
# Vue
git clone https://github.com/bitrix24/templates-dashboard-vue.git <project-name>
git clone https://github.com/bitrix24/starter-b24ui-vue.git <project-name>

cd <project-name>
pnpm install
pnpm run dev
```

### 10.2. Установка в Nuxt **[b24ui]**

```bash
pnpm add @bitrix24/b24ui-nuxt @bitrix24/b24icons-vue tailwindcss
# или: npm install @bitrix24/b24ui-nuxt @bitrix24/b24icons-vue tailwindcss
```

```ts
// nuxt.config.ts
export default defineNuxtConfig({
  modules: ['@bitrix24/b24ui-nuxt'],
  css: ['~/assets/css/main.css']
})
```

```css
/* app/assets/css/main.css */
@import "tailwindcss";
@import "@bitrix24/b24ui-nuxt";
```

```vue
<!-- app.vue -->
<template>
  <B24App>
    <NuxtPage />
  </B24App>
</template>
```

Опции модуля (`b24ui: {…}` в `nuxt.config.ts`): `colorMode` (по умолчанию `true`), `colorModeInitialValue`, `colorModeTypeLight`, `colorModeStorageKey`, `theme.unstyled`, `theme.prefix`, `prose`, `content`, `experimental.componentDetection` (генерировать CSS только для используемых компонентов). Если подключаете `@nuxt/content`, он идёт в `modules` **после** `@bitrix24/b24ui-nuxt`.

### 10.3. Установка в Vue (Vite) **[b24ui]**

```bash
pnpm add @bitrix24/b24ui-nuxt @bitrix24/b24icons-vue tailwindcss
```

```ts
// vite.config.ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import bitrix24UIPluginVite from '@bitrix24/b24ui-nuxt/vite'

export default defineConfig({
  plugins: [
    vue(),
    bitrix24UIPluginVite()          // для Inertia: bitrix24UIPluginVite({ router: 'inertia' })
  ]
})
```

```ts
// src/main.ts
import './assets/css/main.css'
import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import b24UiPlugin from '@bitrix24/b24ui-nuxt/vue-plugin'
import App from './App.vue'

const app = createApp(App)
const router = createRouter({ routes: [], history: createWebHistory() })
app.use(router)
app.use(b24UiPlugin)
app.mount('#app')
```

```css
/* src/assets/css/main.css */
@import "tailwindcss";
@import "@bitrix24/b24ui-nuxt";
```

```vue
<!-- src/App.vue -->
<template>
  <B24App>
    <RouterView />
  </B24App>
</template>
```

Дополнительно для Vue:

- корневому `<div id="app">` в `index.html` добавить `class="isolate"` (`SKILL.md`);
- плагин генерирует `auto-imports.d.ts` и `components.d.ts`: их добавляют в `tsconfig` (`include`) и в `.gitignore`;
- проверка типов должна идти **после** сборки (`"build": "run-s build-only type-check"`), иначе на чистой копии `vue-tsc` упадёт с `Cannot find name 'useToast'`;
- для автодополнения в `tsconfig.node.json` и `tsconfig.app.json` прописывается алиас `#build/b24ui` → `./node_modules/.b24ui-nuxt/b24ui` (полный текст в документации по установке для Vue).

### 10.4. Обязательные правила (из скилла) **[b24ui]**

1. **Всегда оборачивать приложение в `B24App`**: без него не работают тосты, подсказки и программные оверлеи. Язык задаётся пропсом `locale` (`import { ru } from '@bitrix24/b24ui-nuxt/locale'`, если нужная локаль есть среди 20 встроенных).
2. **Только семантические цвета**: `text-description`, `bg-elevated`, `border-muted` и т. д. (раздел 2.2). Никаких `text-gray-500`.
3. **Имена слотов брать из сгенерированных файлов темы**: Nuxt — `.nuxt/b24ui/<component>.ts`, Vue — `node_modules/.b24ui-nuxt/b24ui/<component>.ts`.
4. **Приоритет переопределений** (сильнейшее первым): пропс `b24ui` или `class` → глобальный конфиг → тема по умолчанию.
5. **Иконки искать по гайду иконок** (раздел 6.2).

Способы настроить вид:

- **`b24ui` на одном экземпляре:** `<B24Card :b24ui="{ header: 'bg-muted', body: 'p-8' }" />`.
- **`class`**: меняет только корневой (или `base`) слот.
- **Глобально:** `app.config.ts` в Nuxt (`defineAppConfig({ b24ui: { button: { slots: {…}, defaultVariants: { size: 'lg' } } } })`) или тот же объект в `bitrix24UIPluginVite({ b24ui: {…} })` в Vue. По возможности меняйте `defaultVariants`, а не классы слотов.
- **Для поддерева:** `<B24Theme :b24ui="{ button: { base: 'rounded-full' } }">`. Здесь слоты указываются прямо под именем компонента, без `slots`.
- **Заменить классы, а не дописать к ним:** передать в слот функцию: `:b24ui="{ label: () => 'text-base font-bold' }"`.
- **CSS-переменные** в `main.css`: `--b24ui-container-width`, `--b24ui-header-height`, `--max-height-popup-list` (по умолчанию `40rem`), `--max-height-popup-menu` (по умолчанию `40vh`), цвета графиков `--b24ui-chart-1…6`.

### 10.5. Выбор компонента (кратко из `component-selection.md`) **[b24ui]**

| Задача | Компонент |
|---|---|
| Подтверждение, короткая форма, фокус на одном действии | `B24Modal` (из кода — `useOverlay()`) |
| Детали записи, настройки сбоку | `B24Slideover` |
| Мобильная «шторка» снизу | `B24Drawer` |
| Подсказка при наведении (без кнопок внутри!) | `B24Tooltip` |
| Контекст рядом с элементом | `B24Popover` |
| Основная навигация | `B24NavigationMenu` (в боковой панели — `orientation="vertical"`) |
| Виды на одной странице без своего адреса | `B24Tabs` |
| Положение в иерархии | `B24Breadcrumb` |
| Поиск и команды (Cmd+K) | `B24CommandPalette` + `defineShortcuts` |
| Действия по кнопке | `B24DropdownMenu` |
| Пошаговый мастер | `B24Stepper` |
| Отклик на действие («Сохранено») | `useToast()` |
| Постоянное сообщение в контексте | `B24Alert` |
| Объявление на всё приложение | `B24Banner` |
| Загрузка | `B24Skeleton`, `B24Progress` |
| Выбор варианта с иконкой и описанием | `B24PageCardGroup` |
| Форма входа или регистрации | `B24Form` + `B24FormField` + `B24Input` (отдельного компонента авторизации нет) |

### 10.6. Рецепты скилла (коротко) **[b24ui]**

| Рецепт | О чём | Файл |
|---|---|---|
| Dashboard | Боковое меню, панели, тулбар, разделение список/деталь | `references/layouts/dashboard.md` |
| Data tables | Таблица с поиском, фильтрами, выбором строк, пагинацией, асинхронной загрузкой | `references/recipes/data-tables.md` |
| Forms | `B24Form` + схема (Zod, Valibot, Yup, Joi), раскладка полей, ошибки, форма редактирования записи, форма в модалке | `references/guidelines/forms.md` |
| Overlays | Подтверждение, `useOverlay`, промо-модалка, форма в Slideover, Command palette, Drawer, всплывающая карточка сущности, KPI-виджет | `references/recipes/overlays.md` |
| Navigation | Шапка с мобильным меню, боковое меню, вкладки | `references/recipes/navigation.md` |
| Settings / Card pickers | Switch, Radio, Checkbox, `B24PageCardGroup` | `references/recipes/settings.md`, `card-pickers.md` |
| Detail panel | Боковая панель «о записи» | `references/recipes/detail-panel.md` |
| Task form | Форма задачи: редактор, исполнители, срок | `references/recipes/task-form.md` |

Скилл лежит в репозитории: https://github.com/bitrix24/b24ui/tree/main/skills/b24-ui-nuxt

### 10.7. Как подключить агенту официальный скилл и документацию **[b24ui]**

```bash
npx skills add bitrix24/b24ui                        # любой поддерживаемый агент
npx skills add bitrix24/b24ui --agent claude-code    # только для Claude Code
npx skills add bitrix24/b24ui --agent cursor
npx skills add bitrix24/b24ui --global               # для всех проектов
```

После установки скилл вызывается командой `/b24-ui-nuxt`. Для Claude Code документация b24ui приводит ещё команду `claude skill add https://github.com/bitrix24/b24ui/tree/main/skills/b24-ui-nuxt`. Есть ли у вашей версии Claude Code такая подкоманда, мы не проверяли; надёжнее пользоваться `npx skills add`.

Документация для ИИ: https://bitrix24.github.io/b24ui/llms.txt (около 5 тыс. токенов, начинать с неё) и https://bitrix24.github.io/b24ui/llms-full.txt (больше миллиона токенов, только для больших контекстов).

---

## 11. Чек-лист приёмки «похоже на Битрикс24»

Проверять глазами и поиском. Команды даны для пути Б; файл `b24-tokens.css` из поиска исключён.

**Путь Б:**

1. ☐ `b24-tokens.css` подключён первым и относительным путём: `grep -rn "b24-tokens.css" --include=*.html --include=*.php .`
2. ☐ Новых цветовых литералов нет: `grep -rnE "#[0-9a-fA-F]{3,8}\b|rgba?\(" --include=*.css --include=*.html --include=*.php . | grep -v b24-tokens.css` даёт пустой вывод (допустимы только исключения из промта `#00000059`, `#00000040`, `#00000022` и номера вроде `#4821` в тексте).
3. ☐ Нет тёмной темы: `grep -rn "prefers-color-scheme" .` даёт пусто.
4. ☐ Нет высоты «на весь экран»: `grep -rnE "100vh|100dvh|100svh|height:\s*100%" --include=*.css .` даёт пусто (если страница живёт в портале).
5. ☐ `position:fixed` есть только у модалки и тоста: `grep -rn "position:\s*fixed" .`
6. ☐ Тени есть только у модалки и меню, градиентов нет: `grep -rnE "box-shadow|gradient\(" --include=*.css .`
7. ☐ Нет веб-шрифтов и иконочных шрифтов: `grep -rnE "@font-face|fonts.googleapis|font-awesome|material-icons|bootstrap-icons" .` даёт пусто; эмодзи в интерфейсе нет.
8. ☐ Иконки — инлайн-SVG с `stroke-width="1.8"` и `stroke="currentColor"`.
9. ☐ Есть правило `prefers-reduced-motion`, переходы не длиннее 0,18 с: `grep -rnE "transition|animation" --include=*.css .`
10. ☐ `[hidden]` работает: у элементов со своим `display` правило записано через `:not([hidden])`. Проверка: поставить элементу `hidden`, он должен исчезнуть.
11. ☐ На экране не больше двух синих кнопок; переходы в шапке — `.btn-ghost`.
12. ☐ Выбранное состояние везде одинаковое (brand-soft / brand-ink / 600): в меню, чипах, переключателе видов, списке подсказок.
13. ☐ Цифры, даты и ID набраны `--mono` + `tabular-nums`, колонки чисел в таблице не «прыгают».
14. ☐ Статус всегда подписан словом, не только цветом.
15. ☐ У кнопок-иконок есть `aria-label`; фокус с клавиатуры (Tab) виден на каждом элементе.
16. ☐ Внутри портала нет второй полосы прокрутки и белой полосы под содержимым, окно подстраивается при смене раздела.
17. ☐ На ширине ≤880px левое меню скрыто, вёрстка не ломается.

**Путь А:**

18. ☐ Корень обёрнут в `<B24App>`: `grep -rn "<B24App" .`
19. ☐ Нет сырых цветов Tailwind: `grep -rnE "(text|bg|border|ring)-(gray|slate|zinc|neutral|stone|red|blue|green|yellow|orange|indigo|sky)-[0-9]{2,3}" --include=*.vue .` даёт пусто.
20. ☐ На экране не больше одного `color="air-primary"`; удаление — `air-primary-alert`; у кнопок нет пропса `variant`.
21. ☐ Иконки импортируются по умолчанию из `@bitrix24/b24icons-vue/...`, в `:icon` передан компонент, а не строка.
22. ☐ Нет переключателей темы (`B24ColorMode*`) и `colorModeInitialValue: 'auto'`.

---

## 12. Готовый промт для агента

Скопируйте и отдайте агенту вместе с этим файлом (`bitrix24-style.md`) и `b24-tokens.css`.

````text
Приведи интерфейс этого проекта к стилю приложений Битрикс24 (дизайн-система Air).
Подробный справочник — bitrix24-style.md, готовые токены и стили — b24-tokens.css (оба рядом).
Стиль спокойный и плотный: светлый фон, белые карточки с тонкой рамкой, один синий акцент,
без теней и градиентов (исключения ниже).

## 0. Выбери путь
- Проект на Vue/Nuxt → ставь @bitrix24/b24ui-nuxt + @bitrix24/b24icons-vue, собирай из компонентов
  B24*, цвета — только семантические (air-primary, text-description…). Раздел 10 справочника.
- Иначе (HTML/CSS/JS, PHP-шаблон) → правила ниже и b24-tokens.css. Пути не смешивать.

## 1. Токены — вставить как есть и пользоваться только ими
:root {
  --brand:#0075ff; --brand-hover:#0072f8; --brand-ink:#0154c8; --brand-soft:#e6f4ff;
  --page:#f1f4f6; --surface:#fff; --surface-2:#fbfbfb;
  --line:#00000014; --line-strong:#0000002e;
  --ink:#1a1c1f; --ink-2:#525c69; --ink-3:#828b95;
  --ok:#22ac43; --wait:#af8b35; --stop:#d1483f;
  --folder:#c98a2e;
  --r:6px;
  --ui:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
*{box-sizing:border-box}
body{margin:0;background:var(--page);color:var(--ink);font:400 14px/1.5 var(--ui);-webkit-font-smoothing:antialiased}
a{color:var(--brand);text-decoration:none} a:hover{text-decoration:underline}
- Тёмную тему не делаем: никаких prefers-color-scheme: dark и переключателей темы.
- Текст: --ink основной, --ink-2 вторичный, --ink-3 подписи, даты, счётчики.
- Статусы: --ok / --wait (жёлто-коричневый) / --stop. Фон статуса — color-mix(in srgb, var(--ok) 10–14%, transparent), рамка — тот же color-mix на 30–40%.
- Выбранное или активное состояние всегда одно: фон --brand-soft, текст --brand-ink, font-weight:600.

## 2. Типографика
- Шрифт системный (--ui), веб-шрифты не подключаем. Базовый размер 13–14px, интерфейс компактный.
- h1: 21px / 650, letter-spacing:-.018em, отступ снизу 18px. Заголовок секции: 13.5px / 650.
- Кикер (h5, подписи групп): 700 10.5px, uppercase, letter-spacing:.09em, цвет --ink-3.
- Цифры, даты, размеры, длительности, ID — --mono + font-variant-numeric:tabular-nums. ID рядом с заголовком: 500 12px mono --ink-3.
- Крупные метрики: 17–25px / 650–700, letter-spacing:-.01…-.02em. Подпись над ними: 10.5px uppercase 700 --ink-3.
- Длинный текст: max-width:74ch, 13.4px, line-height:1.6, цвет --ink-2.

## 3. Раскладка
- Шапка .topbar: белая, padding:11px 18px, border-bottom:1px solid var(--line). Действия справа — одним блоком с margin-left:auto. Кнопки-переходы в шапке бледные (белые с рамкой), не синие.
- Страница: .pane{padding:18px 20px 26px; max-width:1180px; margin:0 auto}.
- С левым меню: grid-template-columns:270px minmax(0,1fr). Меню белое, border-right:1px solid var(--line). Пункт: padding:7px 9px, radius 6px, 13.2px, --ink-2; hover --surface-2; выбранный — brand-soft/brand-ink/600. Счётчик справа — mono 11px --ink-3. Группы — кикер 10px uppercase. На ширине ≤880px меню прячется.
- Контент: padding:24px 30px 40px. Сетка карточек: repeat(auto-fit,minmax(258px,1fr)), gap:16px; ряды метрик — 3–4 колонки, gap:10px.

## 4. Компоненты
- Карточка: background:var(--surface); border:1px solid var(--line-strong) (или --line); border-radius:8–10px; padding:14–16px. Без теней.
- Основная кнопка .btn: 600 13px, padding:9px 14px, radius var(--r), фон --brand, текст белый, без рамки; hover --brand-hover; disabled opacity:.55; фокус outline:2px solid var(--brand); outline-offset:2px. На экране одна, максимум две синие.
- Второстепенная .btn-ghost: прозрачный фон, border:1px solid var(--line-strong), текст --ink-2, hover --surface-2. Ряд кнопок: gap:8px, прижат вправо.
- Кнопка-иконка: 27×27, radius 6px, без рамки, цвет --ink-3; hover — фон --surface-2, цвет --ink. Группы иконок разделяем чертой 1×16px --line-strong. Обязателен aria-label.
- Поле ввода и поиск: высота 38px, фон --surface-2, border:1px solid var(--line-strong), radius 6px, 13.5px; placeholder --ink-3; фокус outline:2px solid var(--brand); outline-offset:-1px. Лупа — SVG внутри поля слева (left:11px, padding-left:32px).
- Чип/фильтр: 500 11.5px, padding:6px 9px, radius:100px, рамка --line-strong, фон --surface-2, текст --ink-2. Включённый — brand-soft/brand-ink, рамка brand 30%.
- Пилюля статуса: 600 11px, padding:5px 9px, radius:100px, точка 6px currentColor; текст статусного цвета, фон 10%, рамка 40%.
- Вкладки (подчёркиванием): 600 13px, padding:8px 14px, border-bottom:2px solid transparent, margin-bottom:-1px, под рядом линия --line. Активная — текст --brand-ink, подчёркивание --brand.
- Вкладки-кнопки (переключатель видов): 600 12.5px, padding:8px 13px, белые с рамкой; включённая — brand-soft/brand-ink.
- Таблица-список: внешняя рамка --line-strong, radius 10px, overflow:hidden; без вертикальных линий, между строками --line; hover строки --surface-2. Шапка: 11px uppercase, letter-spacing:.04em, фон --surface-2, текст --ink-2. Ячейки padding:9px 10px. Числа — mono. Сортировка — SVG-шеврон.
- Прогресс: высота 5–8px, radius:100px, дорожка --line или --surface-2, заливка --brand (или статусный цвет).
- Аватар: круг 26–28px, фон --brand-soft, текст --brand-ink, инициалы 700 10.5px.
- Хлебные крошки: белый блок 38px с рамкой. Сегмент padding:3px 6px, radius 5px, текущий — --ink 600, разделитель — --ink-3 11px.
- Пустое состояние: по центру, padding:60px 20px, заголовок --ink-2 14px bold, текст 12.8px --ink-3, max-width:44ch.
- Предупреждение: текст --wait, фон wait 12%, рамка wait 30%, radius 6px, padding:9px 11px, 12px.
- Модалка: белая, рамка --line-strong, radius 10px, box-shadow:0 24px 60px #00000040, ширина 720px, подложка #00000059, появление opacity + scale(.97→1) за .16s.
- Тост: внизу по центру, фон --ink, текст --page, 600 12.5px, radius:100px, padding:9px 16px.
- Выпадающий список: белый, radius 10px, box-shadow:0 10px 30px #00000022, пункты padding:8px 10px, radius 6px, группы подписаны кикером.

## 5. Иконки
Только инлайн-SVG в стиле линий: viewBox="0 0 24 24" fill="none", stroke="currentColor" stroke-width="1.8", stroke-linejoin="round". 14–18px в строках и кнопках, 26–30px в крупных карточках. Цвет — из color родителя. Папки — --folder, файлы — --ink-3. Эмодзи и иконочные шрифты не используем.

## 6. Если страница открывается внутри Битрикс24 (iframe)
- Никаких 100vh и height:100% «на весь экран». Высоту подгоняет портал: подключить <script src="//api.bitrix24.tech/api/v1/"></script> и вызывать BX24.fitWindow(), повторяя через ResizeObserver на body (для встраивания в карточку CRM — вариант с BX24.resizeWindow, раздел 7 справочника). Если приложение открывается через VibeCode — SDK BX24 там не работает: высоту отправлять postMessage({type:'vibe:resize', height}, '*') родителю (раздел 7 справочника).
- Длинные панели (чат, дерево, список) — фиксированная высота в px и прокрутка внутри.
- position:fixed ненадёжен: панели действий — обычным блоком в потоке.
- [hidden] не скрывает элемент со своим display: показывать через .x:not([hidden]){display:flex}.

## 7. Анимации
Только короткие переходы .12–.18s ease по opacity, transform и цвету. Обязательно:
@media (prefers-reduced-motion:reduce){*{transition:none!important;animation:none!important}}

## Как работать
1. Сначала найди все места, где задаются стили (CSS-файлы, <style>, инлайн-стили, классы UI-библиотеки). Покажи список и план: что заменяем на токены, что переверстываем.
2. Цвета, радиусы и шрифты — только через переменные выше. Новых цветов-литералов не вводить.
3. Логику, тексты и разметку данных не менять — только внешний вид.
4. После правок пройди чек-лист из раздела 11 справочника, покажи, где посмотреть результат, и опиши главные визуальные изменения.
````

---

## 13. Первоисточники

**Библиотека b24ui**

- Репозиторий: https://github.com/bitrix24/b24ui (сверено с коммитом `9d98b4a` от 16.10.2026, версия пакета 2.15.0)
- Документация: https://bitrix24.github.io/b24ui/
- Установка в Nuxt: https://bitrix24.github.io/b24ui/docs/getting-started/installation/nuxt/
- Установка в Vue: https://bitrix24.github.io/b24ui/docs/getting-started/installation/vue/
- Markdown-версии страниц (для агентов, ссылки из `llms.txt`):
  - Design System: https://bitrix24.github.io/b24ui/raw/docs/getting-started/theme/design-system.md
  - CSS Variables: https://bitrix24.github.io/b24ui/raw/docs/getting-started/theme/css-variables.md
  - Customize components: https://bitrix24.github.io/b24ui/raw/docs/getting-started/theme/components.md
  - Color Mode (Nuxt / Vue): https://bitrix24.github.io/b24ui/raw/docs/getting-started/integrations/color-mode/nuxt.md · …/color-mode/vue.md
  - Icons (Nuxt / Vue): https://bitrix24.github.io/b24ui/raw/docs/getting-started/integrations/icons/nuxt.md · …/icons/vue.md
  - Skills: https://bitrix24.github.io/b24ui/raw/docs/getting-started/ai/skills.md
  - LLMs.txt: https://bitrix24.github.io/b24ui/raw/docs/getting-started/ai/llms-txt.md
  - Список компонентов: https://bitrix24.github.io/b24ui/raw/docs/components.md (страница компонента — `…/raw/docs/components/<имя>.md`, например `button.md`, `card.md`, `table.md`, `modal.md`)
- Для ИИ: https://bitrix24.github.io/b24ui/llms.txt · https://bitrix24.github.io/b24ui/llms-full.txt
- Официальный скилл: https://github.com/bitrix24/b24ui/tree/main/skills/b24-ui-nuxt (`SKILL.md`, `references/guidelines/*`, `references/layouts/*`, `references/recipes/*`)
- Значения токенов в исходниках: `src/runtime/air-design-tokens/` (`001_b24_global.css`, `003_b24_context_light.css`, `007_b24_global.css`, `008_ui_global.css`, `components/button.css`, `components/popup.css`, `tw-style/*.css`), семантические классы — `src/runtime/index.css`, темы компонентов — `src/theme/*.ts`.

**Иконки:** https://bitrix24.github.io/b24icons/ (каталог: https://bitrix24.github.io/b24icons/icons/)

**JS SDK Битрикс24** (документация b24ui ссылается на него): https://bitrix24.github.io/b24jssdk/

**Шаблоны:** templates-dashboard, starter-b24ui, templates-dashboard-vue, starter-b24ui-vue на https://github.com/bitrix24

**Материалы KiselevLab:** базовый промт стиля (раздел 12); учебный шаблон «Битрикс24: расширенное приложение» из «4 шаблонов для Б24», файл `www/js/frame.js` (подгонка высоты окна). Парный справочник — `bitrix24-apps.md` (данные, ключи, встраивание, деплой).

**VibeCode** (встраивание, BX24 SDK под обработчиком, авто-высота): https://vibecode.bitrix24.tech/docs-content/infra/app-runtime.md · общий вход https://vibecode.bitrix24.tech/docs
