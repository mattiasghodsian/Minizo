import jwt from 'jsonwebtoken';

export const getToken = (req, res) => {

  const usr       = process.env.AUTH_USER;
  const pass      = process.env.AUTH_PASS;
  const jwtSecret = process.env.JWTSECRET;

  const username  = req.query.username;
  const password  = req.query.password;

  if (username == usr && password == pass) {
    const token = jwt.sign({ user: username }, jwtSecret, { expiresIn: '8h' });
    return res.send({
      message: 'Authentication successful',
      token: token
    });
  }
  return res.status(401).json({ message: 'Credentials rejected' });
};

export const verifyToken = (req, res, next) => {

  const authHeader = req.headers.authorization;
  const jwtSecret = process.env.JWTSECRET;
  
  if (req.originalUrl.includes("/api/info") || req.originalUrl.includes("/api/auth")){
    next();
  }else {
    try {
      const token = authHeader.split(' ')[1]; // "Bearer <token>"
      jwt.verify(token, jwtSecret, (err, user) => {
        if (err) {
          return res.status(403).json({ message: 'Unauthorized' });
        }
        next();
      });
    } catch (error) {
      return res.status(403).json({ message: 'Unauthorized' });
    }
  }
};