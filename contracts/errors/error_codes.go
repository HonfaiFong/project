package errors

import "net/http"

type ErrorCode int

const (
	OK ErrorCode = 0

	// 100xxxx：认证领域
	AuthInvalidCredentials         ErrorCode = 1001001
	AuthTokenExpired               ErrorCode = 1001002
	AuthTokenInvalid               ErrorCode = 1001003
	AuthTokenRevoked               ErrorCode = 1001004
	AuthUnauthorized               ErrorCode = 1001005
	AuthDeviceInvalid              ErrorCode = 1001006
	AuthTicketExpired              ErrorCode = 1001007
	AuthSocialProviderUnsupported  ErrorCode = 1001008
	AuthSocialCodeInvalid          ErrorCode = 1001009
	AuthSocialUpstreamError        ErrorCode = 1001010
	AuthSocialBindTokenInvalid     ErrorCode = 1001011
	AuthSMSCodeInvalid             ErrorCode = 1001012
	AuthSMSCodeExpired             ErrorCode = 1001013
	AuthSocialProviderAlreadyBound ErrorCode = 1001014
	AuthSocialBindConflict         ErrorCode = 1001015
	AuthSocialBindPhoneChanged     ErrorCode = 1001016

	// 200xxxx：用户领域
	UserNotFound         ErrorCode = 2001001
	UserAlreadyExists    ErrorCode = 2001002
	UserBanned           ErrorCode = 2001003
	PersonaNotFound      ErrorCode = 2002001
	PersonaForbidden     ErrorCode = 2002002
	PersonaLimitExceeded ErrorCode = 2002003

	// 300xxxx：内容领域
	ContentPostNotFound    ErrorCode = 3001001
	ContentPostDeleted     ErrorCode = 3001002
	ContentCommentNotFound ErrorCode = 3002001
	ContentUnderReview     ErrorCode = 3003001

	// 400xxxx：社交领域
	SocialCannotFollowSelf ErrorCode = 4001001
	SocialAlreadyFollowed  ErrorCode = 4001002
	SocialUserBlocked      ErrorCode = 4002001
	SocialCannotBlockSelf  ErrorCode = 4002002

	// 500xxxx：聊天领域
	ChatConversationNotFound ErrorCode = 5001001
	ChatNotAMember           ErrorCode = 5001002
	ChatForbiddenByPrivacy   ErrorCode = 5001003

	// 600xxxx：消息领域
	MessageNotFound          ErrorCode = 6001001
	MessageDuplicateClientID ErrorCode = 6001002
	MessageSendFailed        ErrorCode = 6001003

	// 700xxxx：风控领域
	RiskActionDenied      ErrorCode = 7001001
	RiskChallengeRequired ErrorCode = 7001002

	// 800xxxx：审核领域
	ModerationRejected ErrorCode = 8001001

	// 900xxxx：基础设施和公共领域
	InternalServerError ErrorCode = 9001001
	InvalidArgument     ErrorCode = 9001002
	RPCTimeout          ErrorCode = 9001003
	RateLimitExceeded   ErrorCode = 9001004
	IdempotencyConflict ErrorCode = 9001005
	ServiceUnavailable  ErrorCode = 9001006
)

func (e ErrorCode) Message() string {
	switch e {
	case OK:
		return "ok"
	case AuthInvalidCredentials:
		return "invalid phone or credentials"
	case AuthTokenExpired:
		return "token expired"
	case AuthTokenInvalid:
		return "token invalid"
	case AuthTokenRevoked:
		return "token revoked"
	case AuthUnauthorized:
		return "unauthorized"
	case AuthDeviceInvalid:
		return "invalid device parameters"
	case AuthTicketExpired:
		return "websocket ticket expired"
	case AuthSocialProviderUnsupported:
		return "social provider is not supported"
	case AuthSocialCodeInvalid:
		return "social authorization code is invalid"
	case AuthSocialUpstreamError:
		return "social provider is temporarily unavailable"
	case AuthSocialBindTokenInvalid:
		return "social bind token is invalid or expired"
	case AuthSMSCodeInvalid:
		return "SMS verification code is invalid"
	case AuthSMSCodeExpired:
		return "SMS verification code is expired"
	case AuthSocialProviderAlreadyBound:
		return "social provider is already bound"
	case AuthSocialBindConflict:
		return "social identity binding conflict"
	case AuthSocialBindPhoneChanged:
		return "phone cannot be changed for this bind session"
	case UserNotFound:
		return "user not found"
	case UserAlreadyExists:
		return "user already exists"
	case UserBanned:
		return "user account is banned"
	case PersonaNotFound:
		return "persona not found"
	case PersonaForbidden:
		return "forbidden persona access"
	case PersonaLimitExceeded:
		return "maximum personas limit reached"
	case ContentPostNotFound:
		return "post not found"
	case ContentPostDeleted:
		return "post has been deleted"
	case ContentCommentNotFound:
		return "comment not found"
	case ContentUnderReview:
		return "content is under review"
	case SocialCannotFollowSelf:
		return "cannot follow yourself"
	case SocialAlreadyFollowed:
		return "already followed"
	case SocialUserBlocked:
		return "user has blocked interaction"
	case SocialCannotBlockSelf:
		return "cannot block yourself"
	case ChatConversationNotFound:
		return "conversation not found"
	case ChatNotAMember:
		return "user is not a member of conversation"
	case ChatForbiddenByPrivacy:
		return "chat cannot be initiated due to privacy or block status"
	case MessageNotFound:
		return "message not found"
	case MessageDuplicateClientID:
		return "duplicate client message id"
	case MessageSendFailed:
		return "failed to send message"
	case RiskActionDenied:
		return "operation denied by risk control"
	case RiskChallengeRequired:
		return "verification challenge required"
	case ModerationRejected:
		return "content rejected by moderation"
	case InternalServerError:
		return "internal server error"
	case InvalidArgument:
		return "invalid request arguments"
	case RPCTimeout:
		return "upstream service rpc timeout"
	case RateLimitExceeded:
		return "rate limit exceeded, please slow down"
	case IdempotencyConflict:
		return "concurrent or conflicting idempotent request in progress"
	case ServiceUnavailable:
		return "service temporarily unavailable"
	default:
		return "unknown error"
	}
}

func (e ErrorCode) HTTPStatus() int {
	switch e {
	case OK:
		return http.StatusOK
	case AuthUnauthorized, AuthTokenExpired, AuthTokenInvalid, AuthTokenRevoked,
		AuthSocialCodeInvalid, AuthSocialBindTokenInvalid, AuthSMSCodeInvalid, AuthSMSCodeExpired:
		return http.StatusUnauthorized
	case AuthInvalidCredentials, PersonaForbidden, ChatForbiddenByPrivacy, SocialUserBlocked:
		return http.StatusForbidden
	case UserNotFound, PersonaNotFound, ContentPostNotFound, ContentCommentNotFound, ChatConversationNotFound, MessageNotFound:
		return http.StatusNotFound
	case UserAlreadyExists, SocialAlreadyFollowed, IdempotencyConflict, MessageDuplicateClientID,
		AuthSocialProviderAlreadyBound, AuthSocialBindConflict, AuthSocialBindPhoneChanged:
		return http.StatusConflict
	case AuthSocialProviderUnsupported, InvalidArgument, AuthDeviceInvalid, AuthTicketExpired, SocialCannotFollowSelf, SocialCannotBlockSelf, PersonaLimitExceeded:
		return http.StatusUnprocessableEntity
	case RateLimitExceeded:
		return http.StatusTooManyRequests
	case AuthSocialUpstreamError, ServiceUnavailable, RPCTimeout:
		return http.StatusServiceUnavailable
	default:
		return http.StatusInternalServerError
	}
}
