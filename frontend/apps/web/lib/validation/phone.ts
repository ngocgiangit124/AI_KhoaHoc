/**
 * Định dạng số điện thoại di động Việt Nam sau chuẩn hoá đầu số 2018 (10 số bắt đầu 0,
 * hoặc +84 theo sau bởi 1 trong các đầu số nhà mạng còn dùng). Chỉ kiểm tra ĐỊNH DẠNG ở
 * client — tính duy nhất/tồn tại vẫn do server xác nhận (api-contract §2.2).
 */
export const PHONE_VN_REGEX = /^(0|\+84)(3|5|7|8|9)\d{8}$/;

export function isValidVnPhone(value: string): boolean {
  return PHONE_VN_REGEX.test(value.trim());
}
