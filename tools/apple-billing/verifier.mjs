import fs from 'node:fs';
import path from 'node:path';
import jwt from 'jsonwebtoken';
import {
  AppStoreServerAPIClient, Environment, SignedDataVerifier, VerificationException, VerificationStatus,
} from '@apple/app-store-server-library';
import { isRetryableVerificationError } from './verification-errors.mjs';

function verifier(config, environment) {
  const certificates = fs.readdirSync(config.root_certificates_path)
    .filter((name) => /\.(cer|der|pem)$/i.test(name))
    .map((name) => fs.readFileSync(path.join(config.root_certificates_path, name)));
  if (!certificates.length || (environment === Environment.PRODUCTION && !Number(config.app_id))) {
    throw new Error('Apple trust configuration is incomplete');
  }
  return new SignedDataVerifier(certificates, true, environment, config.bundle_id, Number(config.app_id) || undefined);
}

async function purchase(request) {
  const { config } = request;
  const environment = request.environment === 'sandbox' ? Environment.SANDBOX : Environment.PRODUCTION;
  const verify = verifier(config, environment);
  const client = new AppStoreServerAPIClient(fs.readFileSync(config.private_key_path, 'utf8'), config.key_id, config.issuer_id, config.bundle_id, environment);
  const info = await client.getTransactionInfo(request.reference);
  const original = await verify.verifyAndDecodeTransaction(info.signedTransactionInfo);
  const statuses = await client.getAllSubscriptionStatuses(original.originalTransactionId);
  const candidates = [];
  for (const group of statuses.data ?? []) {
    for (const status of group.lastTransactions ?? []) {
      if (status.originalTransactionId !== original.originalTransactionId) continue;
      const transaction = await verify.verifyAndDecodeTransaction(status.signedTransactionInfo);
      const renewal = status.signedRenewalInfo ? await verify.verifyAndDecodeRenewalInfo(status.signedRenewalInfo) : {};
      if (transaction.originalTransactionId !== original.originalTransactionId || transaction.type !== 'Auto-Renewable Subscription') {
        throw new Error('Unexpected subscription identity');
      }
      candidates.push({ transaction, renewal, status: status.status });
    }
  }
  candidates.sort((a, b) => (b.transaction.purchaseDate ?? 0) - (a.transaction.purchaseDate ?? 0));
  const current = candidates[0];
  if (!current) throw new Error('Current subscription status is missing');
  const { transaction, renewal, status } = current;
  const state = transaction.revocationDate ? 'revoked' : ({ 1: 'active', 2: 'expired', 3: 'hold', 4: 'grace', 5: 'revoked' }[status]);
  return {
    provider: 'apple', environment: request.environment, identity: transaction.originalTransactionId,
    reference: transaction.originalTransactionId, product_id: transaction.productId,
    account_binding: transaction.appAccountToken, state, auto_renews: renewal.autoRenewStatus === 1,
    transaction_id: transaction.transactionId,
    starts_at: new Date(transaction.purchaseDate).toISOString(),
    transaction_starts_at: new Date(transaction.purchaseDate).toISOString(),
    transaction_ends_at: new Date(transaction.expiresDate).toISOString(),
    ends_at: new Date(state === 'grace' ? renewal.gracePeriodExpiresDate : transaction.expiresDate).toISOString(),
    revoked_at: transaction.revocationDate ? new Date(transaction.revocationDate).toISOString() : null,
    amount: transaction.price === undefined ? null : transaction.price / 1000, currency: transaction.currency ?? null,
  };
}

async function notification(request) {
  // Both environments use independent signature verification; an unverified JWS never selects trust settings.
  for (const environment of [Environment.PRODUCTION, Environment.SANDBOX]) {
    try {
      const verify = verifier(request.config, environment);
      const outer = await verify.verifyAndDecodeNotification(request.signed_payload);
      if (!outer.notificationUUID) throw new Error('Notification identity missing');
      const transaction = outer.data?.signedTransactionInfo
        ? await verify.verifyAndDecodeTransaction(outer.data.signedTransactionInfo) : null;
      if (outer.data?.signedRenewalInfo) await verify.verifyAndDecodeRenewalInfo(outer.data.signedRenewalInfo);
      return { id: outer.notificationUUID, environment: environment === Environment.SANDBOX ? 'sandbox' : 'production', reference: transaction?.originalTransactionId ?? null, account_binding: transaction?.appAccountToken ?? null };
    } catch (error) {
      if (error instanceof VerificationException && error.status === VerificationStatus.RETRYABLE_VERIFICATION_FAILURE) throw error;
      if (!(error instanceof VerificationException) || environment === Environment.SANDBOX) throw error;
    }
  }
}

async function history(request) {
  const { config } = request;
  const environment = request.environment === 'sandbox' ? Environment.SANDBOX : Environment.PRODUCTION;
  const verify = verifier(config, environment);
  const client = new AppStoreServerAPIClient(fs.readFileSync(config.private_key_path, 'utf8'), config.key_id, config.issuer_id, config.bundle_id, environment);
  const page = await client.getNotificationHistory(request.pagination_token ?? null, { startDate: request.start_date, endDate: request.end_date });
  const notifications = [];
  for (const entry of page.notificationHistory ?? []) {
    const outer = await verify.verifyAndDecodeNotification(entry.signedPayload);
    const transaction = outer.data?.signedTransactionInfo ? await verify.verifyAndDecodeTransaction(outer.data.signedTransactionInfo) : null;
    if (outer.data?.signedRenewalInfo) await verify.verifyAndDecodeRenewalInfo(outer.data.signedRenewalInfo);
    notifications.push({ id: outer.notificationUUID, environment: request.environment, reference: transaction?.originalTransactionId ?? null, account_binding: transaction?.appAccountToken ?? null, signed_payload: entry.signedPayload });
  }
  return { notifications, pagination_token: page.paginationToken ?? null, has_more: page.hasMore ?? false };
}

async function catalog(request) {
  const { config } = request;
  if (!config.subscription_group_id || !config.app_id) throw new Error('Catalog identity is missing');
  const token = jwt.sign({}, fs.readFileSync(config.catalog_private_key_path, 'utf8'), {
    algorithm: 'ES256', keyid: config.catalog_key_id, issuer: config.catalog_issuer_id,
    audience: 'appstoreconnect-v1', expiresIn: 120,
  });
  async function connect(relativePath) {
    const response = await fetch(`https://api.appstoreconnect.apple.com/v1/${relativePath}`, {
      headers: { Authorization: `Bearer ${token}` }, signal: AbortSignal.timeout(10000),
    });
    if (!response.ok) throw new Error('Catalog verification unavailable');
    return response.json();
  }
  const groups = await connect(`apps/${encodeURIComponent(config.app_id)}/subscriptionGroups?limit=200`);
  if (!(groups.data ?? []).some((group) => group.id === config.subscription_group_id)) throw new Error('Unexpected subscription group');
  const subscriptions = await connect(`subscriptionGroups/${encodeURIComponent(config.subscription_group_id)}/subscriptions?limit=200`);
  const subscription = (subscriptions.data ?? []).find((entry) => entry.attributes.productId === request.product_id);
  if (!subscription) throw new Error('Product missing from configured group');
  const attributes = subscription.attributes;
  const period = { ONE_MONTH: 1, THREE_MONTHS: 3, SIX_MONTHS: 6, ONE_YEAR: 12 }[attributes.subscriptionPeriod];
  const unavailable = ['MISSING_METADATA', 'DEVELOPER_REMOVED_FROM_SALE', 'REMOVED_FROM_SALE', 'REJECTED'];
  return {
    product_id: attributes.productId, duration_months: period, service_level: attributes.groupLevel,
    family_sharing: attributes.familySharable, available: request.environment === 'production'
      ? attributes.state === 'APPROVED' : !unavailable.includes(attributes.state),
  };
}

try {
  const input = fs.readFileSync(0, 'utf8');
  if (Buffer.byteLength(input) > 1_048_576) throw new Error('Input too large');
  const request = JSON.parse(input);
  const operations = { purchase, notification, history, catalog };
  if (!Object.hasOwn(operations, request.operation)) throw new Error('Unknown verification operation');
  process.stdout.write(JSON.stringify({ ok: true, data: await operations[request.operation](request) }));
} catch (error) {
  const retryable = isRetryableVerificationError(error);
  process.stdout.write(JSON.stringify({ ok: false, retryable, code: retryable ? 'APPLE_VERIFICATION_UNAVAILABLE' : 'APPLE_VERIFICATION_FAILED' }));
  process.exitCode = 1;
}
