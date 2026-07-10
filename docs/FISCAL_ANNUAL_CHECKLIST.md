# Checklist anual de constantes fiscales (Pinky nómina)

Las constantes fiscales cambian **una vez al año**. Sin Contpaq, Pinky es la
fuente de verdad — esta lista dice **qué actualizar, cuándo y de dónde**.
Todo se edita en **Configuración → Fiscal** (o por seeder/BD donde se indica).

## Enero (vigencia 1 de enero)

| Constante | Dónde en Pinky | Fuente oficial |
|---|---|---|
| **Salario mínimo diario** (`fiscal_minimum_wage_daily`) | Config → Fiscal | CONASAMI / DOF (resolución ~19 de diciembre) |
| **Tarifa ISR semanal** (`fiscal_isr_brackets`, period_type=weekly) | BD / seeder | Anexo 8 RMF (DOF). OJO: la tarifa de Pinky es la variante semanal que reproduce Contpaq (validada 157/157); si el SAT publica una nueva, recalibrar contra recibos reales |
| **Subsidio al empleo** (`fiscal_subsidy_brackets`) | BD / seeder | Decreto de subsidio (DOF) |
| **Tabla CyV patronal** (`fiscal_cyv_brackets`) | BD / seeder | Transitorio reforma pensiones 2020 (sube cada año hasta 2030) |
| **Tarifa ISR ANUAL** (period_type=annual, para el ajuste de dic.) | BD | Art. 152 LISR / Anexo 8 |

## Febrero (vigencia 1 de febrero)

| Constante | Dónde en Pinky | Fuente oficial |
|---|---|---|
| **UMA diaria** (`fiscal_uma_daily`) | `php artisan fiscal:sync-uma` (INEGI API) o Config → Fiscal | INEGI (publica ~10 de enero, vigente 1 de febrero) |
| **Prima de Riesgo de Trabajo** (`fiscal_emp_riesgo_trabajo_pct`) | Config → Fiscal | Declaración anual de siniestralidad (febrero, la determina el IMSS por empresa) |

## Después de actualizar

1. `php artisan fiscal:recalc-sdi --compare` → revisar; `--apply` si procede
   (la UMA cambia el tope del SBC).
2. Recalcular el primer periodo del año y validar contra un recibo a mano.
3. Los periodos ya timbrados NO se recalculan (candado CFDI).

## Diciembre (cierre)

1. Configurar `fiscal_aguinaldo_payment_date` (p. ej. 2026-12-15) — el periodo
   semanal que contiene esa fecha paga el aguinaldo proporcional solo.
   Preview: `php artisan payroll:aguinaldo-preview`.
2. Capturar la tarifa ISR **anual** en `fiscal_isr_brackets` (period_type
   `annual`) y correr `php artisan fiscal:annual-adjustment` — el ajuste del
   Art. 97 se aplica manualmente en la última nómina, validado con el contador.
3. `php artisan fiscal:rebuild-annual-totals` para cerrar los acumulados.
