export const CONTACT_FIELDS = Object.freeze({ address: 'UF_CRM_1795682518', apartment: 'UF_CRM_1532084146058', route: 'UF_CRM_1619006158' });
export const DEAL_FIELDS = Object.freeze({ date: 'UF_CRM_1612779971491', windowStart: 'UF_CRM_1795682623', windowEnd: 'UF_CRM_1795682642', duration: 'UF_CRM_1795679140', comment: 'UF_CRM_1795682687', years: 'UF_CRM_1795682809' });
export const FUNNEL_NAMES = ['Сервисное обслуживание юридических лиц', 'Сервисное обслуживание физических лиц', 'Продажа/Аренда', 'Безвозмездное пользование'];
export const normalizeField = name => String(name).replace(/[^a-z0-9]/gi, '').toLowerCase();
