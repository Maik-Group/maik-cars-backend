> Approved design from 21 October 2026. Local implementation and validation are tracked in the [execution register](08_PHASED_EXECUTION_PLAN.md). The user authorized local implementation on 22 October while keeping native/store acceptance and production activation gated.

## 1. Summary, evidence, and agreed behavior

Deliver native, auto-renewing subscriptions through Apple and Google, with Laravel controlling access and daily usage, Filament managing business configuration, and the Expo app handling store purchases and price reveals.

This section records the approved design. Implementation status is maintained separately in the execution register.

The supplied plan records a prior visual check of all nine contract pages (not repeated during this implementation): :codex-file-citation{path="C:/Users/Mustafa_M_Fares/Downloads/Etkan_MaikCat_Subscriptions_Contract.pdf" purpose="source"}

### Confirmed decisions

| Area | Decision |
|---|---|
| Renewal | Auto-renewing subscriptions with 1, 3, 6, and 12-month periods. |
| Sales channels | Apple IAP and Google Play Billing only. Website Stripe and alternative-billing programs are excluded from this release. |
| Restricted content | Item price, base price, weight, and Pt/Pd/Rh assay values are revealed together. |
| Free content | Search, browsing, images, general descriptions, market charts, and the manual-input calculator. |
| Daily usage | One allowance per unique catalog item per day; repeated reveals that day consume nothing further. |
| Item identity | Use the existing normalized serial family, with item UUID fallback where no serial exists. Duplicate analyses of one serial share an allowance. |
| Overlapping access | Highest active allowance wins; allowances are never added. Usage remains shared across entitlements. |
| Existing accounts | Thirty days of transitional access from the announced cutover. |
| Device policy | Preserve current single-device rules. Moving devices requires logout from the old device or an administrator’s reset. |
| Apple verification | Official Apple Node library invoked through a bounded Laravel helper. |

Defaults chosen for implementation:

- Reset usage at **00:00 UTC**, matching the current backend timezone.
- Transitional access has no daily limit, preserving existing access during the transition. It expires exactly 30 days after cutover, regardless of first login.
- Paid purchases made during the transition start immediately; they do not extend the transition or defer store billing.
- Existing subscription benefits remain snapshotted when plans change. New plan settings apply to new agreements unless an explicitly approved migration says otherwise.
- Initial prices, quotas, product identifiers, and storefront availability are operational configuration. Products remain unavailable until these values are approved and verified.

**Arabic executive summary:** سيتم تنفيذ الاشتراكات المتجددة تلقائياً عبر Apple وGoogle، مع التحقق والصلاحيات والحد اليومي في Laravel. تُحتسب مشاهدة كل رقم صنف موحّد مرة واحدة يومياً، ويحصل المستخدمون الحاليون على فترة انتقالية مدتها 30 يوماً. هذا توسع عن العقد الأصلي الذي استثنى الدفع عبر المتاجر، ويتطلب اعتماد تكلفة ومدة جديدتين.
