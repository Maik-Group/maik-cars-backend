import { APIException, VerificationException, VerificationStatus } from '@apple/app-store-server-library';

export function isRetryableVerificationError(error) {
  if (error instanceof VerificationException) {
    return error.status === VerificationStatus.RETRYABLE_VERIFICATION_FAILURE;
  }
  if (error instanceof APIException) {
    return ![400, 404].includes(error.httpStatusCode);
  }
  return true;
}
