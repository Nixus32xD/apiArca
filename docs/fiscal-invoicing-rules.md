# Reglas de facturacion fiscal

La API fiscal es la fuente de verdad para resolver el tipo de comprobante. El SaaS debe enviar `invoice_mode=auto`, los datos del comercio y, si existen, los datos fiscales del receptor.

## Factura A/B/C

- Factura A: emisor Responsable Inscripto y receptor Responsable Inscripto.
- Factura B: emisor Responsable Inscripto y receptor no Responsable Inscripto.
- Factura C: emisor Monotributista o IVA Exento.

## Matriz emisor/receptor

| Emisor | Receptor | Comprobante |
| --- | --- | --- |
| Monotributista | Consumidor Final | Factura C |
| Monotributista | Responsable Inscripto | Factura C |
| Monotributista | Monotributista | Factura C |
| Monotributista | IVA Exento | Factura C |
| IVA Exento | Cualquier receptor soportado | Factura C |
| Responsable Inscripto | Responsable Inscripto | Factura A |
| Responsable Inscripto | Consumidor Final | Factura B |
| Responsable Inscripto | Monotributista | Factura B |
| Responsable Inscripto | IVA Exento | Factura B |

## Mapping ARCA/AFIP

| Dato | Codigo |
| --- | --- |
| Factura A | `cbte_type=1` |
| Nota de Debito A | `cbte_type=2` |
| Nota de Credito A | `cbte_type=3` |
| Factura B | `cbte_type=6` |
| Nota de Debito B | `cbte_type=7` |
| Nota de Credito B | `cbte_type=8` |
| Factura C | `cbte_type=11` |
| Nota de Debito C | `cbte_type=12` |
| Nota de Credito C | `cbte_type=13` |
| CUIT | `DocTipo=80` |
| DNI | `DocTipo=96` |
| Consumidor Final sin identificar | `DocTipo=99`, `DocNro=0` |

## Flujo recomendado

1. Venta registrada en el SaaS.
2. Pago aprobado o venta confirmada.
3. SaaS llama `POST /api/fiscal/documents` con `invoice_mode=auto`.
4. API fiscal resuelve receptor, comprobante y payload WSFEv1.
5. ARCA/AFIP autoriza o rechaza.
6. SaaS persiste el estado del comprobante y concilia antes de reintentar estados inciertos.

El medio de pago no define el tipo de factura.

## Payload recomendado

```json
{
  "business_id": "tenant-123",
  "origin": {
    "type": "sale",
    "id": "1000"
  },
  "invoice_mode": "auto",
  "point_of_sale": 1,
  "concept": 1,
  "customer": {
    "name": "Cliente SA",
    "document_type": "CUIT",
    "document_number": "30712345671",
    "iva_condition": "responsable_inscripto",
    "address": "Av. Fiscal 123"
  },
  "amounts": {
    "imp_total": 121,
    "imp_neto": 100,
    "imp_iva": 21
  },
  "idempotency_key": "sale-1000-invoice"
}
```

Si `customer` no se envia, la API usa consumidor final sin identificar.

## Validaciones criticas

- Responsable Inscripto receptor requiere CUIT valido.
- Monotributista o Exento receptor con datos fiscales requiere CUIT.
- Factura A solo se permite con emisor y receptor Responsable Inscripto.
- Factura B solo se permite para emisor Responsable Inscripto y receptor no Responsable Inscripto.
- Factura C no se permite para emisor Responsable Inscripto.
- Notas de credito/debito requieren comprobante asociado.
- Para Responsable Inscripto, `ImpIVA` debe cerrar con `amounts.iva_items` y `ImpTotal` con sus componentes fiscales.
- Los medios de pago no definen el tipo de comprobante ARCA; solo se guardan como dato operativo.

La API soporta alicuotas IVA 21%, 10.5%, 27%, 5%, 2.5% y 0% usando los IDs oficiales de WSFEv1.
