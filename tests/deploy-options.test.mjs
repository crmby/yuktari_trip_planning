import test from 'node:test';
import assert from 'node:assert/strict';
import { needsCatalogChoice, catalogSummary, deploymentArguments, selectDeploymentKey } from '../scripts/deploy-options.mjs';
test('управляющий ключ выбирается явно, отказ не переключает источник',()=>{
  const env={VIBE_API_KEY:'personal-test',VIBE_APP_KEY:'vibe_app_test'};
  assert.equal(selectDeploymentKey(env).key,env.VIBE_API_KEY);
  assert.equal(selectDeploymentKey(env,deploymentArguments(['--key-source=app']).keySource).key,env.VIBE_APP_KEY);
  assert.throws(()=>selectDeploymentKey({VIBE_API_KEY:'personal-test'},'app'));
  assert.throws(()=>deploymentArguments(['--key-source=api','--key-source=app']));
  assert.throws(()=>deploymentArguments(['--key-source=unknown']));
});
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
