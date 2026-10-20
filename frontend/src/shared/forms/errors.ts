import { ApiError } from '../api/client';

/** The server's validation message for one field, if the last submit was rejected because of it. */
export function fieldError(error: unknown, field: string): string | undefined {
  return error instanceof ApiError ? error.fieldError(field) : undefined;
}

/**
 * A message for the top of the form: anything that is not shown next to a field
 * (network errors, permissions, validation errors for fields this form does not have).
 * `shownFields` is the list of fields rendered inline, or a function that says so.
 */
export function formError(error: unknown, shownFields: string[] | ((field: string) => boolean)): string | null {
  if (!error) {
    return null;
  }

  if (!(error instanceof ApiError)) {
    return 'حصل خطأ';
  }

  if (error.status === 422) {
    const isShown = typeof shownFields === 'function' ? shownFields : (field: string) => shownFields.includes(field);
    const hidden = Object.entries(error.errors).find(([field]) => !isShown(field));

    return hidden ? (hidden[1][0] ?? error.message) : null;
  }

  return error.message;
}
