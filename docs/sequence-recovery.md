# Recuperación de numeración fiscal

Cada emisión CAE consulta `FECompUltimoAutorizado` bajo el lock distribuido de la secuencia fiscal. Por eso, después de restaurar la configuración fiscal y las credenciales, la próxima emisión vuelve a basarse en ARCA y reserva `último ARCA + 1`.

Para revisar una secuencia sin emitir ni alterar datos se puede usar:

```http
POST /api/fiscal/companies/{external_fiscal_id}/sequences/reconcile
Authorization: Bearer {token-interno}
Content-Type: application/json

{
  "point_of_sale": 2,
  "cbte_type": 11
}
```

La respuesta devuelve el último número autorizado por ARCA, el máximo local de documentos y reservas, y `safe_to_issue`. Si existe un documento local incierto/en proceso o el máximo local supera al de ARCA, devuelve un estado que requiere revisión y no propone un número siguiente.

La operación queda auditada como request entrante y consulta WSFEv1 saliente. No reconstruye ventas, PDFs ni credenciales: se requieren backups de base de datos, almacenamiento y secretos para una recuperación completa.
