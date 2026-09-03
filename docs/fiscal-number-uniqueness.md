# Unicidad de numeración fiscal

## Conclusión

**UNIQUE recomendable: sí.** Un comprobante fiscal con número asignado debe ser
único por empresa fiscal, punto de venta y tipo de comprobante.

Los estados `draft`, `error` y los rechazos anteriores a la reserva mantienen
`document_number = NULL`; la semántica de `UNIQUE` de MySQL permite esos
registros transitorios. CAE, CAEA, `processing` e `uncertain` reciben el número
por `FiscalSequenceReservation` antes de autorizar o informar. Retry y
reconcile reutilizan el mismo documento y la misma reserva, no crean una fila
final adicional.

La migración restaura la protección en `fiscal_documents` sólo después de un
preflight. Si detecta duplicados históricos con número no nulo, registra las
claves y aborta sin borrar ni fusionar datos.
