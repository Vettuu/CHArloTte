# Knowledge base (multi-tenant)

Struttura consigliata:

```
resources/knowledge/<tenant_id>/metadata.json
resources/knowledge/<tenant_id>/*.md
resources/knowledge/<tenant_id>/*.json
```

Esempio tenant demo:
```
resources/knowledge/demo/metadata.json
resources/knowledge/demo/evento_generale.md
resources/knowledge/demo/dettagli.json
```

Rigenera l’indice per un tenant specifico:
```
php artisan knowledge:index --tenant=demo
```
