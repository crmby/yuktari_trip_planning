import test from 'node:test';
import assert from 'node:assert/strict';
import { needsCatalogChoice, catalogSummary } from '../scripts/deploy-options.mjs';
test('вопрос о каталоге нужен только без выбора и без существующей карточки',()=>{
  assert.equal(needsCatalogChoice({showInBitrix24:'NOT_CHOSEN',b24CatalogSync:{itemId:null}}),true);
  for (const showInBitrix24 of ['YES','NO']) assert.equal(needsCatalogChoice({showInBitrix24,b24CatalogSync:{itemId:null}}),false);
  assert.equal(needsCatalogChoice({showInBitrix24:'NOT_CHOSEN',b24CatalogSync:{itemId:45}}),false);
  assert.equal(needsCatalogChoice({showInBitrix24:'NOT_CHOSEN'}),false);
});
test('статус каталога отличает показ, скрытие, очередь и неизвестное состояние',()=>{
  assert.match(catalogSummary({showInBitrix24:'YES',b24CatalogSync:{itemId:45,status:'SYNCED'}}),/показывается/);
  assert.match(catalogSummary({showInBitrix24:'NO',b24CatalogSync:{itemId:null,status:'SYNCED'}}),/скрыто/);
  assert.match(catalogSummary({showInBitrix24:'NO',b24CatalogSync:{itemId:45,pendingOp:'DELETE'}}),/в очереди/);
  assert.match(catalogSummary({showInBitrix24:'YES',b24CatalogSync:{itemId:null,status:'FAILED'}}),/требует проверки/);
  assert.match(catalogSummary({}),/недоступно/);
});
