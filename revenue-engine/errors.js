export class RevenueError extends Error {
  constructor(code, message, status = 400) {
    super(message);
    this.code = code;
    this.status = status;
  }
}

export function ensure(condition, code, message, status = 400) {
  if (!condition) throw new RevenueError(code, message, status);
}
