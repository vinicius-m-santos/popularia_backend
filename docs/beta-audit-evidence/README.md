# Evidências da auditoria V1

Resultado interpretado e roteiro em [v1-freeze-audit.md](../v1-freeze-audit.md).

- `phpunit.log`, `phpstan.log`, `php-cs-fixer.log`: checks finais do backend no container PHP 8.4.
- `jest.log`, `tsc.log`, `lint.log`, `expo-doctor.log`: checks finais mobile. Jest encerrou sem forceExit.
- `export-android.log`, `export-ios.log`: exports com um worker após a queda do WSL. Não são builds nativas assinadas.
- `native-config.json`: recorte sem secrets do Expo introspect com env completo dos perfis EAS staging/production. Entitlements assinados e permissões mescladas ainda precisam do binário real.
- `schema.log`: mapping correto, sync global com diferenças descritas no relatório.
- `schema-diff.sql`: diagnóstico; **NÃO EXECUTAR**. Contém remoções de índices/FKs intencionais que não devem ser aplicadas.
- `query-plans.php`: diagnóstico reproduzível no banco local `_test`, 5 mil usuários/500 comunas/20 mil posts, transação desfeita ao final. Execute depois da suíte que prepara as migrations; não concorra com PHPUnit nem aponte para banco compartilhado. ANALYZE pode deixar estatísticas transitórias após rollback; a próxima execução padrão de PHPUnit recria o banco de testes.
- `query-plans.json`: SQL e EXPLAIN ANALYZE BUFFERS reais da fixture, sem dados de usuários reais. Uma amostra local; não são percentis de produção nem medição de todas as queries HTTP.

Checagens públicas em 30/10/2026: health de staging/produção HTTP 200 e database ok; AASA staging com details vazio, assetlinks staging vazio; ambos os documentos em produção HTTP 404. Não houve alteração remota. O inventário Railway consultado não demonstrou um serviço worker/cron separado; execução em scheduler externo não foi verificada.
