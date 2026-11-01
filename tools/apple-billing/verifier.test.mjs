import assert from 'node:assert/strict';
import test from 'node:test';
import { APIException, Environment, SignedDataVerifier, VerificationException, VerificationStatus } from '@apple/app-store-server-library';
import { isRetryableVerificationError } from './verification-errors.mjs';

const verifier = new SignedDataVerifier([], true, Environment.SANDBOX, 'maik.cat.ios');
const encode = (value) => Buffer.from(JSON.stringify(value)).toString('base64url');

for (const signedPayload of ['not-a-jws', `${encode({ alg: 'none' })}.${encode({ bundleId: 'maik.cat.ios', environment: 'Sandbox', appAccountToken: 'forged', expiresDate: Date.now() + 86400000 })}.`, `${encode({ alg: 'ES256', x5c: [] })}.${encode({ notificationUUID: 'forged-notification' })}.Zm9yZ2Vk`]) {
  test(`untrusted Apple payload is rejected (${signedPayload.split('.')[0]})`, async () => {
    await assert.rejects(() => verifier.verifyAndDecodeTransaction(signedPayload), VerificationException);
    await assert.rejects(() => verifier.verifyAndDecodeNotification(signedPayload), VerificationException);
  });
}

test('certificate transport and server failures remain retryable while invalid proof is terminal', () => {
  for (const [error, retryable] of [
    [new VerificationException(VerificationStatus.RETRYABLE_VERIFICATION_FAILURE), true],
    [new VerificationException(VerificationStatus.INVALID_ENVIRONMENT), false],
    [new APIException(429), true], [new APIException(503), true],
    [new APIException(401), true], [new APIException(404), false],
  ]) assert.equal(isRetryableVerificationError(error), retryable);
});
